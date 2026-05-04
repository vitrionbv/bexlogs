<?php

namespace App\Http\Controllers\Api;

use App\Events\LogBatchInserted;
use App\Events\ScrapeJobUpdated;
use App\Http\Controllers\Controller;
use App\Models\BexSession;
use App\Models\LogMessage;
use App\Models\Page;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Support\LogMessageHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WorkerController extends Controller
{
    /**
     * Sentinel string the Playwright worker emits when it sees a 401/403 on
     * /load_more_logs.js or lands on /sign_in. Mirrors `SESSION_EXPIRED_SENTINEL`
     * in `scraper/src/scrape.ts` — keep in sync. We detect it inside `fail()`
     * to stamp `stats.stop_reason = session_expired` without requiring the
     * worker to send a separate field.
     */
    private const SESSION_EXPIRED_SENTINEL = 'SESSION_EXPIRED';

    /**
     * Allowed values for `stats.stop_reason`. Mirrors the `StopReason`
     * union in `scraper/src/types.ts` plus the `worker_reaped` value
     * emitted by `ScrapeReapStale`. The Jobs UI maps these to
     * human-readable labels in `pages/Jobs/Index.vue`.
     *
     * Two-tier semantics:
     *   - completions: `duplicate_detection` (caught up to already-scraped
     *     territory), `caught_up` (caught up to BookingExperts' live log
     *     tip — the AWS CloudWatch `nextForwardToken === inputToken`
     *     analogue, surfaced via the scraper's token-echo retry helper),
     *     `empty_window`, `pagination_limit`, `time_limit`.
     *   - failures: `pagination_error` (422 exhausted retries),
     *     `token_missing` (next_token went null mid-scrape — distinct
     *     from `caught_up`, which is "same token came back"),
     *     `unparseable`, `runaway_safety`, `session_expired`,
     *     `worker_reaped`.
     *
     * Retired keys (historical rows may still carry them; the Jobs UI
     * falls back to a labeled legacy badge or hides the badge entirely):
     *   - `natural_end` — split into `duplicate_detection` /
     *     `caught_up` / `token_missing` / `pagination_error`.
     *   - `token_echo` — replaced by `caught_up`. The scraper now
     *     retries echoes (interpreted as the AWS-style log-tail signal)
     *     and either advances or completes with `caught_up` on
     *     exhaustion. Legacy rows still render via the Jobs UI's
     *     "Token echo (legacy)" fallback badge.
     */
    public const STOP_REASONS = [
        'duplicate_detection',
        'caught_up',
        'pagination_limit',
        'time_limit',
        'pagination_error',
        'token_missing',
        'unparseable',
        'runaway_safety',
        'empty_window',
        'session_expired',
        'worker_reaped',
    ];

    /**
     * Pull the next queued job (FIFO) and mark it running.
     * Response 200 with job, or 204 if nothing to do.
     */
    public function nextJob(): JsonResponse|SymfonyResponse
    {
        $job = DB::transaction(function () {
            $candidate = ScrapeJob::query()
                ->where('status', ScrapeJob::STATUS_QUEUED)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $candidate) {
                return null;
            }

            $candidate->update([
                'status' => ScrapeJob::STATUS_RUNNING,
                'attempts' => $candidate->attempts + 1,
                'started_at' => now(),
                'last_heartbeat_at' => now(),
            ]);

            return $candidate->load('subscription.application.organization', 'bexSession');
        });

        if (! $job) {
            return response()->noContent(204);
        }

        $session = $job->bexSession;
        $sub = $job->subscription;
        $app = $sub->application;
        $org = $app->organization;

        broadcast(new ScrapeJobUpdated(
            userId: (int) $org->user_id,
            jobId: $job->id,
            subscriptionId: (string) $job->subscription_id,
            status: $job->status,
        ));

        return response()->json([
            'id' => $job->id,
            'subscription' => [
                'id' => $sub->id,
                'name' => $sub->name,
                'environment' => $sub->environment,
                'organization_id' => $org->id,
                'application_id' => $app->id,
            ],
            'session' => [
                'id' => $session->id,
                'environment' => $session->environment,
                'cookies' => $session->cookies,
            ],
            'params' => $job->params ?? [],
        ]);
    }

    public function heartbeat(ScrapeJob $job): SymfonyResponse
    {
        $job->update(['last_heartbeat_at' => now()]);

        return response()->noContent();
    }

    /**
     * Receive a batch of parsed log messages from the worker.
     * Body: { messages: [{ timestamp, type, action, method, status, parameters?, request?, response? }, ...] }
     */
    public function batch(Request $request, ScrapeJob $job): JsonResponse
    {
        $data = $request->validate([
            'messages' => 'required|array',
            'pages_processed' => 'nullable|integer|min:0',
            'messages.*.timestamp' => 'required|string',
            'messages.*.type' => 'required|string',
            'messages.*.action' => 'required|string',
            'messages.*.method' => 'required|string',
            'messages.*.path' => 'nullable|string',
            'messages.*.status' => 'nullable|string',
            'messages.*.parameters' => 'nullable',
            'messages.*.request' => 'nullable',
            'messages.*.response' => 'nullable',
        ]);

        $sub = $job->subscription;
        $app = $sub->application;
        $org = $app->organization;

        $page = Page::query()->firstOrCreate([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $sub->id,
        ]);

        // PDO binds string params as text, which Postgres rejects for bytea
        // columns (invalid UTF-8). Use a typed bytea literal for PG; on other
        // drivers (sqlite for tests) the raw 32 bytes go through fine.
        $driver = DB::connection()->getDriverName();
        $bindHash = static fn (string $hash): mixed => $driver === 'pgsql'
            ? DB::raw("'\\x".bin2hex($hash)."'::bytea")
            : $hash;

        $rows = collect($data['messages'])
            ->map(function (array $m) use ($page, $bindHash) {
                $path = isset($m['path']) && $m['path'] !== '' ? (string) $m['path'] : null;
                $hash = LogMessageHasher::compute(
                    timestamp: $m['timestamp'],
                    type: $m['type'],
                    action: $m['action'],
                    method: $m['method'],
                    status: $m['status'] ?? null,
                    parameters: $m['parameters'] ?? null,
                    request: $m['request'] ?? null,
                    response: $m['response'] ?? null,
                    path: $path,
                );

                return [
                    'page_id' => $page->id,
                    'timestamp' => $m['timestamp'],
                    'type' => $m['type'],
                    'action' => $m['action'],
                    'method' => $m['method'],
                    'path' => $path,
                    'status' => $m['status'] ?? null,
                    'parameters' => isset($m['parameters']) ? json_encode($m['parameters']) : null,
                    'request' => isset($m['request']) ? json_encode($m['request']) : null,
                    'response' => isset($m['response']) ? json_encode($m['response']) : null,
                    // Pre-hex form keyed for batch dedup; the actual bind value
                    // is a typed bytea literal so PG won't try to UTF-8-decode it.
                    '__hex' => bin2hex($hash),
                    'content_hash' => $bindHash($hash),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            // Postgres' ON CONFLICT cannot affect the same target row twice in
            // a single statement; dedupe within the batch by the new unique
            // key (page_id, content_hash). Identical-payload re-emissions
            // collapse to one row — that's the whole point of pagination
            // overlap dedup. Different payloads with the same timestamp keep
            // separate rows.
            ->unique(fn (array $r) => $r['page_id'].'|'.$r['__hex'])
            ->map(function (array $r) {
                unset($r['__hex']);

                return $r;
            })
            ->values()
            ->all();

        $receivedInBatch = count($rows);
        $pagesProcessed = isset($data['pages_processed']) ? (int) $data['pages_processed'] : null;

        [$stored, $mergedStats] = DB::transaction(function () use ($job, $rows, $receivedInBatch, $pagesProcessed) {
            $locked = ScrapeJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

            // Use the Query Builder's insertOrIgnore — NOT Eloquent's
            // upsert(). Eloquent's `Builder::upsert` calls
            // `addUpdatedAtToUpsertColumns()` which silently appends
            // `updated_at` to the update list whenever the model uses
            // timestamps. The Query Builder then sees a non-empty update
            // list and compiles to `ON CONFLICT (...) DO UPDATE SET
            // updated_at = excluded.updated_at` — Postgres reports
            // `affected_rows = inserts + updates`, so the returned row
            // count would equal `count($rows)` regardless of how many
            // were genuinely new. That breaks the scraper's
            // duplicate-detection early-stop (which fires only when
            // `pageReceived > 0 && pageInserted === 0` per batch
            // response) and inflates `rows_inserted` to match
            // `rows_received` on every overlap scrape.
            //
            // `insertOrIgnore` compiles to `ON CONFLICT DO NOTHING` on
            // Postgres (relies on the existing
            // `log_messages_page_content_hash_idx` unique index) and to
            // `INSERT OR IGNORE` on SQLite. PDO's `rowCount()` returns
            // the genuinely-new insert count on both drivers — exactly
            // the semantic the early-stop logic needs. The DB::raw
            // bytea bind for `content_hash` survives because
            // insertOrIgnore doesn't re-process row values; it just
            // parameterizes them through the same Grammar path the
            // upsert call used.
            $stored = empty($rows)
                ? 0
                : DB::table('log_messages')->insertOrIgnore($rows);

            // Use the in-batch deduped count (count($rows) == $receivedInBatch),
            // not the raw $data['messages'] count. The collection above
            // already collapses identical-payload rows in this same batch
            // via ->unique(page_id|hash) — those in-batch dups aren't
            // cross-run duplicates and shouldn't be double-counted in
            // total_duplicates. This counter measures only what the
            // (page_id, content_hash) unique index rejected at insert
            // time, which IS the cross-batch / cross-run dedup signal
            // the scraper's early-stop and the Jobs UI care about.
            $duplicates = max(0, $receivedInBatch - (int) $stored);

            $prev = $locked->stats ?? [];
            $merged = array_merge($prev, [
                'rows_received' => (int) ($prev['rows_received'] ?? 0) + $receivedInBatch,
                'rows_inserted' => (int) ($prev['rows_inserted'] ?? 0) + (int) $stored,
                'total_duplicates' => (int) ($prev['total_duplicates'] ?? 0) + $duplicates,
                'batches' => (int) ($prev['batches'] ?? 0) + 1,
                'last_batch_at' => now()->toIso8601String(),
            ]);

            if ($pagesProcessed !== null) {
                $merged['pages_processed'] = max(
                    (int) ($prev['pages_processed'] ?? 0),
                    $pagesProcessed,
                );
            }

            $locked->update([
                'last_heartbeat_at' => now(),
                'stats' => $merged,
            ]);

            return [$stored, $merged];
        });

        if ($stored > 0) {
            $latestTimestamp = collect($rows)->pluck('timestamp')->max();
            $totalInPage = (int) LogMessage::where('page_id', $page->id)->count();

            broadcast(new LogBatchInserted(
                userId: (int) $org->user_id,
                pageId: (int) $page->id,
                subscriptionId: (string) $sub->id,
                inserted: (int) $stored,
                totalInPage: $totalInPage,
                latestTimestamp: $latestTimestamp,
            ));
        }

        broadcast(new ScrapeJobUpdated(
            userId: (int) $org->user_id,
            jobId: $job->id,
            subscriptionId: (string) $job->subscription_id,
            status: $job->fresh()->status,
            stats: $mergedStats,
        ));

        return response()->json([
            'received' => $receivedInBatch,
            'inserted' => $stored,
        ]);
    }

    public function complete(Request $request, ScrapeJob $job): SymfonyResponse
    {
        $stats = $request->validate([
            'pages' => 'nullable|integer',
            // Legacy field — pre-fix the worker sent this as
            // `pages_processed × BATCH_SIZE`, which gave the Jobs UI
            // suspiciously round "rows" counts (150, 100, 125 = 6/4/5
            // pages × 25) instead of real row counts. Current workers
            // no longer send it; the validator still accepts it for
            // backward compat with an in-flight rolling deploy, but
            // the merge below drops it before persisting so it can't
            // re-appear in `stats` and re-pollute the Jobs UI.
            // `rows_received` / `rows_inserted` from the /batch endpoint
            // are the contract.
            'rows' => 'nullable|integer',
            'duration_ms' => 'nullable|integer',
            'aborted_due_to_time' => 'nullable|boolean',
            'early_stopped_due_to_duplicates' => 'nullable|boolean',
            'total_duplicates' => 'nullable|integer',
            // Diagnostic counter for the token-echo retry helper (see
            // `scraper/src/scrape.ts`). Always sent by the worker, even
            // when 0, so the Jobs detail dialog can surface whether the
            // helper fired on a given run. Not surfaced in any badge —
            // pure observability.
            'token_echo_retries' => 'nullable|integer|min:0',
            'stop_reason' => ['nullable', 'string', 'in:'.implode(',', self::STOP_REASONS)],
        ]);

        // array_filter strips keys whose value is null so we don't clobber
        // existing per-batch counters (`rows_received`, `rows_inserted`,
        // `batches`, `last_batch_at`, `pages_processed`) with nulls when
        // the worker omits a field. Any explicit value — including
        // `stop_reason` — overlays cleanly via array_merge.
        //
        // `rows` is explicitly dropped here so a rolling-deploy worker
        // still sending the legacy field can't re-pollute `stats.rows`
        // on new completions. Old completed rows that already carry the
        // field stay untouched (we don't backfill-delete) — the Jobs UI
        // simply stops surfacing it.
        $incoming = array_filter($stats, fn ($v) => $v !== null);
        unset($incoming['rows']);
        $current = $job->fresh();
        $mergedStats = array_merge($current->stats ?? [], $incoming);

        $current->update([
            'status' => ScrapeJob::STATUS_COMPLETED,
            'completed_at' => now(),
            'stats' => $mergedStats,
        ]);

        Subscription::where('id', $current->subscription_id)->update([
            'last_scraped_at' => now(),
        ]);

        broadcast(ScrapeJobUpdated::fromJob($current->fresh()));

        return response()->noContent();
    }

    public function fail(Request $request, ScrapeJob $job): SymfonyResponse
    {
        $payload = $request->validate([
            'error' => 'required|string',
            'retryable' => 'nullable|boolean',
            'stop_reason' => ['nullable', 'string', 'in:'.implode(',', self::STOP_REASONS)],
        ]);

        $update = [
            'status' => ScrapeJob::STATUS_FAILED,
            'completed_at' => now(),
            'error' => $payload['error'],
        ];

        // Resolve stop_reason in priority order:
        //   1. Whatever the worker explicitly sent (the new path —
        //      scraper sets pagination_error, token_missing, unparseable,
        //      runaway_safety inline before calling /fail).
        //   2. SESSION_EXPIRED sentinel detection (legacy fallback for
        //      older worker builds and the catch-block path that doesn't
        //      know to label session expiry as anything other than the
        //      sentinel error string).
        //   3. Nothing (generic failure — UI hides the badge).
        $explicitReason = $payload['stop_reason'] ?? null;
        $effectiveReason = $explicitReason
            ?? ($payload['error'] === self::SESSION_EXPIRED_SENTINEL ? 'session_expired' : null);

        if ($effectiveReason !== null) {
            $update['stats'] = array_merge(
                $job->stats ?? [],
                ['stop_reason' => $effectiveReason],
            );
        }

        $job->update($update);

        broadcast(ScrapeJobUpdated::fromJob($job->fresh()));

        return response()->noContent();
    }

    public function sessionExpired(BexSession $session): SymfonyResponse
    {
        $session->update(['expired_at' => now()]);

        return response()->noContent();
    }
}
