<?php

namespace App\Console\Commands;

use App\Models\LogArchiveManifest;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Move aged-out log_messages rows from the hot Postgres table into
 * compressed JSONL objects on the `cold-logs` filesystem disk
 * (Hetzner Object Storage in prod, the local fake in tests).
 *
 * Per-subscription contract:
 *   1. Skip subscriptions with NULL `archive_after_days` (feature off).
 *   2. Select log_messages rows older than
 *      `now() - archive_after_days days` and belonging to this sub
 *      via the page → subscription FK.
 *   3. Group the candidate rows by their UTC calendar date. Each
 *      group becomes one object on disk at
 *      `logs/{subscription_id}/{YYYY}/{MM}/{DD}.jsonl.gz`.
 *   4. If the day's object already exists (a previous archive run
 *      partially completed), read it, merge the new rows in, dedupe
 *      by (page_id, content_hash), and write the combined set back.
 *      That preserves idempotency on retried runs.
 *   5. Upsert the manifest row for (subscription, date) so the
 *      cold reader can find it without a Storage::files() LIST call.
 *   6. DELETE the source rows from log_messages in a transaction.
 *      The DELETE is gated on the upload + manifest write succeeding;
 *      if either fails we abort BEFORE the delete so a transient
 *      Hetzner outage can't drop data.
 *
 * Per-run upper bound: `MAX_PER_SUB = 50_000` rows. Larger backlogs
 * drain over multiple nightly ticks. The contract is identical to
 * `bex:apply-retention`: take time, never starve the live INSERT path.
 *
 * Tests fake the disk with `Storage::fake('cold-logs')` — no Hetzner
 * round-trips happen in CI.
 */
class BexArchiveCold extends Command
{
    /**
     * The cold-storage disk identifier defined in
     * `config/filesystems.php`. Using a constant prevents a typo
     * from silently writing to the wrong disk.
     */
    public const DISK = 'cold-logs';

    /**
     * Hard upper bound per subscription per run. Smaller than
     * apply-retention's bound because each archived row triggers
     * an upload (more expensive than a delete) — 50k rows is
     * roughly the EuroParcs-shaped daily volume on a single
     * subscription, so a busy operator's first archive run
     * comfortably covers a single day in one cron tick.
     */
    private const MAX_PER_SUB = 50_000;

    /**
     * Per-archive-pass DELETE chunk size. Mirrors apply-retention's
     * batching to keep autovacuum behaviour predictable.
     */
    private const DELETE_CHUNK = 10_000;

    protected $signature = 'bex:archive-cold
                            {--subscription= : Limit the run to a single subscription_id (debugging).}
                            {--dry-run : Compute the upload plan without uploading or deleting.}';

    protected $description = 'Archive aged-out log_messages rows into compressed JSONL on the cold-logs disk.';

    public function handle(): int
    {
        $singleSub = $this->option('subscription');
        $dryRun = (bool) $this->option('dry-run');

        $query = Subscription::query()->whereNotNull('archive_after_days');
        if ($singleSub !== null) {
            $query->where('id', (string) $singleSub);
        }

        /** @var EloquentCollection<int, Subscription> $subscriptions */
        $subscriptions = $query->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No subscriptions have archive_after_days set; nothing to archive.');

            return self::SUCCESS;
        }

        $totalArchived = 0;

        foreach ($subscriptions as $sub) {
            $archived = $this->archiveSubscription($sub, $dryRun);
            $totalArchived += $archived;
        }

        $this->info(sprintf(
            '%s %d row(s) across %d subscription(s).',
            $dryRun ? 'Would archive' : 'Archived',
            $totalArchived,
            $subscriptions->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Archive up to {@see MAX_PER_SUB} aged-out rows for one
     * subscription. Returns the genuine archived-row count
     * (zero in dry-run).
     */
    private function archiveSubscription(Subscription $sub, bool $dryRun): int
    {
        $cutoff = Carbon::now()->subDays((int) $sub->archive_after_days);
        $cutoffString = $cutoff->toIso8601String();

        $pageIds = DB::table('pages')
            ->where('subscription_id', $sub->id)
            ->pluck('id');

        if ($pageIds->isEmpty()) {
            return 0;
        }

        // Pull a bounded candidate set ordered chronologically so
        // we always close out older days before newer days. That
        // keeps the manifest "watermark" advancing monotonically
        // and means a partial run (interrupted before the budget
        // ceiling) leaves the system in a clean state.
        $rows = $this->fetchCandidateRows($pageIds, $cutoffString);

        if ($rows->isEmpty()) {
            return 0;
        }

        // Bucket the candidate set by UTC calendar date. The grouping
        // matches the on-disk object key shape exactly.
        $byDate = $rows->groupBy(fn ($row) => $this->utcDate((string) $row->timestamp));

        $archived = 0;
        foreach ($byDate as $date => $group) {
            $archived += $this->archiveDay(
                sub: $sub,
                date: (string) $date,
                rows: $group,
                dryRun: $dryRun,
            );
        }

        return $archived;
    }

    /**
     * Pull aged-out rows belonging to one subscription out of
     * Postgres. We hex-encode the bytea `content_hash` server-side
     * so PHP gets a clean string back regardless of driver — PG's
     * raw bytea would arrive as a stream resource (see the note in
     * `App\Models\LogMessage::$hidden`).
     */
    private function fetchCandidateRows(Collection $pageIds, string $cutoffString): Collection
    {
        $driver = DB::connection()->getDriverName();
        $hashSelect = match ($driver) {
            'pgsql' => DB::raw("encode(content_hash, 'hex') as content_hash_hex"),
            // SQLite (tests) stores the raw bytes; hex-encode in PHP
            // post-fetch — done in `mapToJsonlEntry` below.
            default => DB::raw('content_hash as content_hash_hex'),
        };

        return collect(DB::table('log_messages')
            ->whereIn('page_id', $pageIds)
            ->where('timestamp', '<', $cutoffString)
            ->orderBy('timestamp')
            ->orderBy('id')
            ->limit(self::MAX_PER_SUB)
            ->select([
                'id',
                'page_id',
                'timestamp',
                'type',
                'action',
                'method',
                'path',
                'status',
                'parameters',
                'request',
                'response',
                $hashSelect,
                'created_at',
                'updated_at',
            ])
            ->get());
    }

    /**
     * Materialise one (sub, date) bucket: read any existing JSONL
     * from disk, merge the in-memory rows, dedupe by (page_id, hex
     * content_hash), gzip, upload, manifest-upsert, delete source.
     *
     * Returns the count of source rows actually deleted (zero in
     * dry-run mode).
     */
    private function archiveDay(Subscription $sub, string $date, Collection $rows, bool $dryRun): int
    {
        $key = $this->objectKey((string) $sub->id, $date);
        $disk = Storage::disk(self::DISK);

        $newEntries = $rows->map(fn ($row) => $this->mapToJsonlEntry($row));
        $sourceIds = $rows->pluck('id')->all();

        if ($dryRun) {
            $this->line(sprintf(
                ' • subscription=%s date=%s rows=%d (dry-run)',
                $sub->id,
                $date,
                $rows->count(),
            ));

            return 0;
        }

        // Merge with the existing object if a previous run partially
        // completed. The dedup key is (page_id, content_hash_hex),
        // which mirrors the hot-table unique index.
        $merged = $this->mergeWithExisting($disk, $key, $newEntries);

        $serialized = $this->serializeJsonl($merged);
        $compressed = gzencode($serialized, 6);
        if ($compressed === false) {
            // gzencode only fails on out-of-memory or invalid level
            // — neither should ever fire in production. Bail loudly
            // rather than silently uploading an empty file.
            throw new \RuntimeException("Failed to gzip archive payload for {$sub->id}/{$date}");
        }

        $uploaded = $disk->put($key, $compressed);
        if ($uploaded === false) {
            throw new \RuntimeException("Failed to upload archive object {$key}");
        }

        DB::transaction(function () use ($sub, $date, $key, $merged, $sourceIds) {
            LogArchiveManifest::query()->updateOrCreate(
                [
                    'subscription_id' => $sub->id,
                    'date' => $date,
                ],
                [
                    's3_key' => $key,
                    'row_count' => $merged->count(),
                    'archived_at' => now(),
                ],
            );

            // Delete in chunks. The candidate set per day is bounded
            // by the per-sub ceiling (50k) but we still chunk to
            // keep WAL pressure consistent with the retention path.
            foreach (array_chunk($sourceIds, self::DELETE_CHUNK) as $idChunk) {
                DB::table('log_messages')->whereIn('id', $idChunk)->delete();
            }
        });

        Log::info('bex:archive-cold archived day', [
            'subscription_id' => $sub->id,
            'date' => $date,
            's3_key' => $key,
            'merged_count' => $merged->count(),
            'newly_added' => $newEntries->count(),
        ]);

        $this->line(sprintf(
            ' • subscription=%s date=%s rows=%d key=%s',
            $sub->id,
            $date,
            $merged->count(),
            $key,
        ));

        return count($sourceIds);
    }

    /**
     * If an object already exists for this (sub, date), pull it down,
     * decompress, parse it as one JSON object per line, then merge
     * the in-memory new entries on top using (page_id, content_hash)
     * as the dedup key. The merge preserves the existing entries
     * for any duplicate keys — JSONL on disk is the source of truth
     * once written, and re-archiving identical rows is a no-op.
     */
    private function mergeWithExisting(Filesystem $disk, string $key, Collection $newEntries): Collection
    {
        $existing = collect();
        if ($disk->exists($key)) {
            $raw = $disk->get($key);
            if ($raw !== null && $raw !== '') {
                $decompressed = @gzdecode($raw);
                if ($decompressed === false) {
                    throw new \RuntimeException("Failed to gzdecode existing archive object {$key}");
                }
                $existing = collect(self::parseJsonl($decompressed));
            }
        }

        // Merge with existing-wins semantics: a row already on disk
        // is canonical, so re-archiving the same (page_id, hash)
        // tuple from a transient retry is a no-op.
        $byKey = $existing->keyBy(self::dedupKeyFn());
        foreach ($newEntries as $entry) {
            $k = self::dedupKeyFn()($entry);
            if (! $byKey->has($k)) {
                $byKey->put($k, $entry);
            }
        }

        // Sort chronologically before serialising so the on-disk
        // file is deterministic — easier to diff manually if an
        // operator ever pulls the .jsonl.gz down to inspect.
        return $byKey->values()->sortBy(fn (array $entry) => $entry['timestamp'] ?? '')->values();
    }

    /**
     * Convert a candidate row from the DB into the JSONL-friendly
     * shape we serialise. The JSON columns come back as text from
     * the Query Builder; we decode them so the on-disk file is one
     * coherent JSON document per line rather than a string-in-string.
     *
     * `content_hash_hex` is the hex form (32 hex chars = 16 bytes
     * for sqlite raw, or 64 chars for pg's bytea-as-hex) — the
     * dedup key the cold reader uses, and the merge key the
     * archive command's own existing-object branch uses.
     */
    private function mapToJsonlEntry(object $row): array
    {
        $hashHex = (string) ($row->content_hash_hex ?? '');
        // SQLite returns raw bytes; encode to hex client-side so
        // both drivers share the same on-disk format.
        if ($hashHex !== '' && ! ctype_xdigit($hashHex)) {
            $hashHex = bin2hex($hashHex);
        }

        return [
            'id' => (int) $row->id,
            'page_id' => (int) $row->page_id,
            'timestamp' => (string) $row->timestamp,
            'type' => (string) $row->type,
            'action' => (string) $row->action,
            'method' => (string) $row->method,
            'path' => $row->path,
            'status' => $row->status,
            'parameters' => self::decodeJson($row->parameters),
            'request' => self::decodeJson($row->request),
            'response' => self::decodeJson($row->response),
            'content_hash' => $hashHex,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * Build the canonical S3 key for one archive object. Kept as a
     * static helper so the cold reader and tests can reproduce the
     * key without depending on this command class.
     */
    public static function objectKey(string $subscriptionId, string $date): string
    {
        // $date is `YYYY-MM-DD`; split it into the path components
        // we want under `logs/{sub}/...` so an operator browsing
        // the bucket via the Hetzner UI sees a tidy year/month tree.
        [$y, $m, $d] = explode('-', $date);

        return sprintf('logs/%s/%s/%s/%s.jsonl.gz', $subscriptionId, $y, $m, $d);
    }

    /**
     * Inverse of {@see objectKey} — given a manifest row's
     * `(subscription_id, date)`, produce the bucket key. Exposed
     * for the cold reader's "fetch this day's blob" path.
     */
    public static function dateKey(string $subscriptionId, Carbon $date): string
    {
        return self::objectKey($subscriptionId, $date->toDateString());
    }

    private function utcDate(string $iso8601): string
    {
        return Carbon::parse($iso8601)->utc()->toDateString();
    }

    /**
     * @param  iterable<array<string,mixed>>  $entries
     */
    public static function serializeJsonl(iterable $entries): string
    {
        $out = '';
        foreach ($entries as $entry) {
            // JSON_UNESCAPED_SLASHES keeps URLs readable; the rest
            // of the flags don't matter for line-delimited JSON.
            $out .= json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function parseJsonl(string $blob): array
    {
        $entries = [];
        foreach (explode("\n", $blob) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, associative: true);
            if (! is_array($decoded)) {
                continue;
            }
            $entries[] = $decoded;
        }

        return $entries;
    }

    private static function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, associative: true);

        return $decoded ?? (string) $value;
    }

    /**
     * Build the (page_id, content_hash) dedup key used by the
     * existing-object merge branch. Returned as a closure so callers
     * can reuse it in `keyBy`/`map` chains.
     *
     * @return callable(array<string,mixed>): string
     */
    private static function dedupKeyFn(): callable
    {
        return fn (array $entry) => sprintf(
            '%s|%s',
            $entry['page_id'] ?? '',
            $entry['content_hash'] ?? '',
        );
    }
}
