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

            $stored = empty($rows)
                ? 0
                : LogMessage::query()->upsert(
                    $rows,
                    ['page_id', 'content_hash'],
                    [] // never overwrite existing — dedup only
                );

            $prev = $locked->stats ?? [];
            $merged = array_merge($prev, [
                'rows_received' => (int) ($prev['rows_received'] ?? 0) + $receivedInBatch,
                'rows_inserted' => (int) ($prev['rows_inserted'] ?? 0) + (int) $stored,
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
            'rows' => 'nullable|integer',
            'duration_ms' => 'nullable|integer',
            'aborted_due_to_time' => 'nullable|boolean',
            'early_stopped_due_to_duplicates' => 'nullable|boolean',
            'total_duplicates' => 'nullable|integer',
        ]);

        $incoming = array_filter($stats, fn ($v) => $v !== null);
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
        ]);

        $job->update([
            'status' => ScrapeJob::STATUS_FAILED,
            'completed_at' => now(),
            'error' => $payload['error'],
        ]);

        broadcast(ScrapeJobUpdated::fromJob($job->fresh()));

        return response()->noContent();
    }

    public function sessionExpired(BexSession $session): SymfonyResponse
    {
        $session->update(['expired_at' => now()]);

        return response()->noContent();
    }
}
