<?php

namespace App\Listeners;

use App\Events\LogBatchInserted;
use App\Jobs\DeliverAlertJob;
use App\Models\LogMessage;
use App\Models\Page;
use App\Models\SavedQuery;
use App\Services\AlertDelivery\AlertPayloadBuilder;
use App\Services\AlertDelivery\DedupeWindow;
use App\Services\AlertDelivery\SavedQueryEvaluator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Subscribes to LogBatchInserted (fired by WorkerController::batch
 * after a batch of log_messages is upserted) and dispatches a
 * DeliverAlertJob per matched (saved_query × channel) pair.
 *
 * The listener is `ShouldQueue` because the broadcast event is
 * `ShouldBroadcastNow` for ordering reasons (see LogBatchInserted's
 * docblock) and we don't want the alert evaluation pipeline running
 * inline on the worker request — that would double the worker's
 * /batch round-trip latency. Sitting on the queue means the matcher
 * runs in the same `php artisan queue:work` container as the delivery
 * jobs themselves.
 *
 * Per-batch flow:
 *   1. Hydrate the page → subscription so we can attach
 *      (subscription_id, environment) context to each log row
 *      (the LogBatchInserted event carries page_id but not the
 *      subscription metadata).
 *   2. Load the user's enabled saved queries (with channels eager-
 *      loaded via the pivot so we don't N+1 inside the row loop).
 *   3. For each newly-inserted log row, evaluate every saved query.
 *      On match, reserve a dedupe slot per channel and dispatch a
 *      DeliverAlertJob per channel.
 *
 * The "newly-inserted log row" detection is best-effort: we re-fetch
 * the latest N rows from the page where N = batch's `inserted` count.
 * This isn't 100% precise (a parallel batch could land between the
 * upsert and our query), but the dedupe window collapses the
 * resulting double-fires and the operator never sees a duplicate
 * delivery.
 */
class AlertOnLogBatchListener implements ShouldQueue
{
    public function __construct(
        private readonly SavedQueryEvaluator $evaluator,
        private readonly AlertPayloadBuilder $builder,
        private readonly DedupeWindow $dedupe,
    ) {}

    public function handle(LogBatchInserted $event): void
    {
        if ($event->inserted <= 0) {
            return;
        }

        $page = Page::query()->with('subscription')->find($event->pageId);
        if (! $page || ! $page->subscription) {
            return;
        }

        $queries = SavedQuery::query()
            ->where('user_id', $event->userId)
            ->where('enabled', true)
            ->with(['channels' => fn ($q) => $q->where('enabled', true)])
            ->get();

        if ($queries->isEmpty()) {
            return;
        }

        // Cap the look-back at the batch size so a paranoid "fetch
        // last 1000 rows just in case" doesn't blow up listener
        // memory on a large page. Bumping this past `event->inserted`
        // by a small margin (×2, capped at 200) tolerates a parallel
        // batch landing between the upsert and our query — the dedupe
        // window absorbs the re-fire.
        $lookback = min(200, max(1, $event->inserted * 2));

        $candidates = LogMessage::query()
            ->where('page_id', $page->id)
            ->orderByDesc('id')
            ->limit($lookback)
            ->get();

        $logContext = [
            'subscription_id' => (string) $page->subscription->id,
            'environment' => (string) $page->subscription->environment,
        ];

        $dispatched = 0;
        foreach ($candidates as $log) {
            foreach ($queries as $query) {
                if (! $this->evaluator->matches($query, $log, $logContext)) {
                    continue;
                }

                $payload = $this->builder->forSavedQueryMatch($query, $log, $logContext);

                foreach ($query->channels as $channel) {
                    $window = (int) ($channel->pivot->dedupe_window_seconds ?? 60);

                    $key = DedupeWindow::buildKey(
                        queryId: (int) $query->id,
                        channelId: (int) $channel->id,
                        // Fingerprint omits the timestamp + payload bodies
                        // so semantically-identical rows collapse. See the
                        // DedupeWindow class doc.
                        fingerprint: [
                            'method' => (string) $log->method,
                            'status' => (string) ($log->status ?? ''),
                            'action' => (string) $log->action,
                            'path' => (string) ($log->path ?? ''),
                        ],
                    );

                    if (! $this->dedupe->reserve($key, $window)) {
                        continue;
                    }

                    DeliverAlertJob::dispatch(
                        alertChannelId: (int) $channel->id,
                        savedQueryId: (int) $query->id,
                        logMessageId: (int) $log->id,
                        payload: $payload,
                    );

                    $dispatched++;
                }
            }
        }

        if ($dispatched > 0) {
            Log::info('AlertOnLogBatchListener: dispatched deliveries', [
                'page_id' => $page->id,
                'user_id' => $event->userId,
                'inserted' => $event->inserted,
                'dispatched' => $dispatched,
            ]);
        }
    }
}
