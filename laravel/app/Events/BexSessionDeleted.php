<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a BexSession row is deleted, either via the operator's
 * "Delete expired sessions" button on the Authenticate page or by the
 * `bex:prune-stale-sessions` artisan command. Lets any open
 * Authenticate page refresh live so the orphan card disappears
 * immediately instead of lingering until the operator hits reload.
 *
 * Mirrors BexSessionRelinked's shape (same `user.{userId}` channel,
 * same ShouldBroadcastNow guarantee, same payload skeleton) so the
 * Vue side can collapse both events into a single
 * `router.reload({ only: ['sessions', 'jobSummary'] })`.
 *
 * `reason` distinguishes the operator paths from automated cleanups in
 * future log diagnostics — today it's always either `orphan_pruned`
 * (BexSessionPruner) or `manual_revoke` (operator delete button).
 */
class BexSessionDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $bexSessionId,
        public string $environment,
        public ?string $accountEmail,
        public string $reason = 'manual_revoke',
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
        return 'bex-session-deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'bex_session_id' => $this->bexSessionId,
            'environment' => $this->environment,
            'account_email' => $this->accountEmail,
            'reason' => $this->reason,
            'at' => now()->toIso8601String(),
        ];
    }
}
