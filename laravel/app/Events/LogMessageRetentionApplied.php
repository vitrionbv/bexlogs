<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by `bex:apply-retention` after a per-subscription pruning pass
 * deletes one or more log_messages rows.
 *
 * Broadcast on:
 *   - private-user.{userId} — the operator's firehose. The Manage
 *     page listens here to refresh the "would prune ~N rows" hint
 *     under the retention input, and the Logs index uses it to
 *     re-render the log_count chip on the affected subscription's
 *     row without a full page reload.
 *
 * `ShouldBroadcastNow` (not the queued variant) so the UI sees the
 * counter snap to its post-prune value the same tick the rows
 * actually disappear from the DB. The retention pass runs nightly at
 * 03:00, so the queue overhead would be hours of stale UI in the
 * worst case if we used a deferred broadcast.
 */
class LogMessageRetentionApplied implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $subscriptionId,
        public int $deletedCount,
        public int $retentionDays,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'log-message-retention-applied';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'subscription_id' => $this->subscriptionId,
            'deleted_count' => $this->deletedCount,
            'retention_days' => $this->retentionDays,
            'at' => now()->toIso8601String(),
        ];
    }
}
