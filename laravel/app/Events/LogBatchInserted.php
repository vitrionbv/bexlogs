<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by WorkerController::batch after a batch of log_messages is upserted.
 *
 * Broadcast on:
 *   - private-page.{pageId}   — the live log feed (Logs/Show.vue)
 *   - private-user.{userId}   — sidebar / dashboard / Logs index refresh
 *
 * `ShouldBroadcastNow` (not the queued variant): the worker ships batches
 * in tight succession during a scrape; if these went through the queue the
 * WS could deliver `inserted=10, total=10` after `inserted=5, total=15`
 * and the Logs/Show feed would flicker backwards. Inline broadcast
 * preserves arrival order at a few-ms cost per batch.
 */
class LogBatchInserted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $pageId,
        public string $subscriptionId,
        public int $inserted,
        public int $totalInPage,
        public ?string $latestTimestamp,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('page.'.$this->pageId),
            new PrivateChannel('user.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'log-batch-inserted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'page_id' => $this->pageId,
            'subscription_id' => $this->subscriptionId,
            'inserted' => $this->inserted,
            'total_in_page' => $this->totalInPage,
            'latest_timestamp' => $this->latestTimestamp,
            'at' => now()->toIso8601String(),
        ];
    }
}
