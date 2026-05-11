<?php

namespace App\Services;

use App\Console\Commands\BexArchiveCold;
use App\Models\LogArchiveManifest;
use App\Models\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Read-side counterpart to {@see App\Console\Commands\BexArchiveCold}.
 *
 * Given a `(Page, fromTs?, toTs?)` query, return the archived rows
 * (decoded from compressed JSONL on the `cold-logs` disk) whose
 * timestamp falls inside the requested window. The Logs UI's page
 * controller merges this output with the hot Postgres rows so an
 * operator filtering for, say, "all of Q1 2026" sees a single
 * paginated list regardless of which tier each row currently lives
 * in.
 *
 * Key design choices:
 *   - The manifest table (`log_archive_manifest`) is the source of
 *     truth for "which days exist on the cold disk?". We do NOT do
 *     `Storage::files(...)` LIST calls — Hetzner charges per-1000
 *     LIST calls and a small Postgres table costs nothing.
 *   - Raw .jsonl.gz blobs are cached locally for 1 hour (see
 *     `CACHE_TTL_SECONDS`). The Logs UI paginates 100 rows at a
 *     time, so a single date range typically gets re-read across
 *     multiple page-loads as the operator scrolls; without the
 *     cache each scroll page would re-pull the same blob from
 *     Hetzner.
 *   - We never decode JSONL inside the cache (cache stores the
 *     compressed bytes). Cache hits do gzdecode + parse on every
 *     request — cheap enough at the 100-row paging size, and it
 *     keeps the on-disk cache from ballooning if the same blob is
 *     decoded into multiple shapes by callers.
 *   - Filtering happens in-memory after decompression. We can't
 *     push predicates into the JSONL format the way the hot
 *     Postgres path does with WHERE/ILIKE — but the per-day
 *     partitioning means each blob contains <= 1 day of rows
 *     (typically <50k entries), so even a multi-MB blob filters
 *     in a few ms of PHP CPU.
 */
class ColdLogReader
{
    /**
     * Cache TTL for raw .jsonl.gz blobs. One hour is the sweet spot
     * for a Logs UI paging session: short enough that a fresh archive
     * pass gets visibility within an hour, long enough that an
     * operator clicking through a multi-page result doesn't refetch
     * Hetzner per click.
     */
    public const CACHE_TTL_SECONDS = 3600;

    /**
     * Cache key prefix. Versioned (`v1`) so a future serialisation
     * change can simply bump this and skip a manual cache flush.
     */
    private const CACHE_KEY_PREFIX = 'cold-logs:v1:';

    /**
     * Look up the manifest rows that intersect the requested
     * timestamp window for the given Page. Returns an empty Collection
     * when the page's subscription has never been archived (the
     * common case for fresh installs).
     *
     * The window is inclusive on both ends and is interpreted as
     * UTC dates. Rows on a manifest day are considered "in window"
     * if the day overlaps the [from, to] range at all — we'll
     * filter out individual rows below the per-blob granularity
     * inside {@see fetchRowsForRange}.
     *
     * @return Collection<int, LogArchiveManifest>
     */
    public function manifestsForRange(Page $page, ?string $fromIso, ?string $toIso): Collection
    {
        $subscriptionId = $page->subscription_id;
        if ($subscriptionId === null) {
            return collect();
        }

        $query = LogArchiveManifest::query()->where('subscription_id', $subscriptionId);

        // Convert the (open-ended) ISO8601 window into UTC dates,
        // padding the range by one day on each side so a query like
        // `between 2026-04-15T23:00Z and 2026-04-16T01:00Z` still
        // pulls both 2026-04-15 and 2026-04-16 blobs.
        if ($fromIso !== null && $fromIso !== '') {
            $query->where('date', '>=', Carbon::parse($fromIso)->utc()->toDateString());
        }
        if ($toIso !== null && $toIso !== '') {
            $query->where('date', '<=', Carbon::parse($toIso)->utc()->toDateString());
        }

        return $query->orderBy('date')->get();
    }

    /**
     * Returns the merged, filtered set of archived rows for the
     * given Page and range. The shape of each row matches the JSONL
     * entries the archive command writes — see
     * {@see BexArchiveCold::mapToJsonlEntry()}.
     *
     * `additionalFilters` is an associative array of optional column
     * predicates (case-insensitive substring) that mirror the hot
     * Postgres path:
     *   - `q`        → free-text needle matched across `action`,
     *                  `path`, `method`, and the JSON bodies.
     *   - `type`     → exact match on `type`.
     *   - `entity`   → first whitespace-separated token of `action`.
     *   - `action`   → exact match on `action`.
     *   - `method`   → exact match on `method`.
     *   - `status`   → exact match on `status`.
     *
     * @param  array<string, mixed>  $additionalFilters
     * @return Collection<int, array<string, mixed>>
     */
    public function fetchRowsForRange(
        Page $page,
        ?string $fromIso,
        ?string $toIso,
        array $additionalFilters = [],
    ): Collection {
        $manifests = $this->manifestsForRange($page, $fromIso, $toIso);
        if ($manifests->isEmpty()) {
            return collect();
        }

        $entries = collect();
        foreach ($manifests as $manifest) {
            $blob = $this->fetchBlobBytes($manifest);
            if ($blob === null) {
                continue;
            }

            $decompressed = @gzdecode($blob);
            if ($decompressed === false) {
                Log::warning('cold-logs: gzdecode failed', [
                    's3_key' => $manifest->s3_key,
                    'subscription_id' => $manifest->subscription_id,
                ]);

                continue;
            }

            foreach (BexArchiveCold::parseJsonl($decompressed) as $entry) {
                // Filter on the source page first — cold blobs are
                // keyed by subscription, not page, so a subscription
                // that owns multiple pages would otherwise leak
                // rows from a sibling page into this query.
                if ((int) ($entry['page_id'] ?? 0) !== (int) $page->id) {
                    continue;
                }

                if (! $this->rowInsideTimeWindow($entry, $fromIso, $toIso)) {
                    continue;
                }

                if (! $this->matchesAdditionalFilters($entry, $additionalFilters)) {
                    continue;
                }

                $entries->push($entry);
            }
        }

        // Newest-first matches the hot Logs UI's default sort. The
        // controller sorts again after merging hot + cold, so this
        // is just a debugging-friendly default.
        return $entries
            ->sortByDesc(fn (array $entry) => $entry['timestamp'] ?? '')
            ->values();
    }

    /**
     * Pull the raw compressed bytes for a single manifest entry,
     * either from the local cache or from the cold disk. Returns
     * NULL when the object can't be found (e.g. a manifest row
     * exists but the bucket was nuked manually).
     */
    private function fetchBlobBytes(LogArchiveManifest $manifest): ?string
    {
        $cacheKey = self::CACHE_KEY_PREFIX.$manifest->s3_key;

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $disk = Storage::disk(BexArchiveCold::DISK);
        if (! $disk->exists($manifest->s3_key)) {
            Log::warning('cold-logs: manifest references a missing object', [
                's3_key' => $manifest->s3_key,
                'subscription_id' => $manifest->subscription_id,
                'date' => $manifest->date,
            ]);

            return null;
        }

        $bytes = $disk->get($manifest->s3_key);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        Cache::put($cacheKey, $bytes, self::CACHE_TTL_SECONDS);

        return $bytes;
    }

    private function rowInsideTimeWindow(array $entry, ?string $fromIso, ?string $toIso): bool
    {
        $ts = (string) ($entry['timestamp'] ?? '');

        // ISO8601 strings are lexicographically chronological, so a
        // direct string compare is enough — same trick the apply
        // retention path uses on the hot side.
        if ($fromIso !== null && $fromIso !== '' && $ts < $fromIso) {
            return false;
        }
        if ($toIso !== null && $toIso !== '' && $ts > $toIso) {
            return false;
        }

        return true;
    }

    /**
     * Apply the same set of facet predicates the hot path supports.
     * Implemented as case-insensitive substring matches because we
     * lost SQL's collation-aware semantics by going through gzipped
     * JSON — `Str::contains` with `ignoreCase: true` is the closest
     * stable equivalent.
     *
     * @param  array<string, mixed>  $filters
     */
    private function matchesAdditionalFilters(array $entry, array $filters): bool
    {
        foreach (['type', 'action', 'method', 'status'] as $col) {
            if (! empty($filters[$col]) && (string) ($entry[$col] ?? '') !== (string) $filters[$col]) {
                return false;
            }
        }

        if (! empty($filters['entity'])) {
            $entity = (string) $filters['entity'];
            $action = (string) ($entry['action'] ?? '');
            $first = strtok($action, ' ');
            // Match either the full action title (single-word
            // entities like "Webhook") or the first whitespace
            // token (multi-word actions like "Reservation updated").
            if (strcasecmp((string) $first, $entity) !== 0
                && strcasecmp($action, $entity) !== 0) {
                return false;
            }
        }

        if (! empty($filters['q'])) {
            $needle = strtolower((string) $filters['q']);
            $haystack = strtolower(implode(' ', [
                (string) ($entry['action'] ?? ''),
                (string) ($entry['path'] ?? ''),
                (string) ($entry['method'] ?? ''),
                json_encode($entry['parameters'] ?? null) ?: '',
                json_encode($entry['request'] ?? null) ?: '',
                json_encode($entry['response'] ?? null) ?: '',
            ]));
            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sum the manifest row counts for a subscription. Used by the
     * Manage page to render the "X rows already archived" hint
     * under the archive_after_days input without doing any S3
     * round-trips.
     */
    public function archivedRowCount(string $subscriptionId): int
    {
        return (int) LogArchiveManifest::query()
            ->where('subscription_id', $subscriptionId)
            ->sum('row_count');
    }
}
