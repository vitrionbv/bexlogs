<?php

namespace App\Events;

use App\Models\BexSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by BexSessionController::store when the extension's captured
 * cookies match an existing session by (user_id, environment,
 * account_email) and we update that row in-place instead of inserting a
 * new one. Lets the Authenticate page refresh the affected card live
 * without the "ghost new session" flash that `router.reload` alone can
 * cause.
 *
 * Mirrors ScrapeJobUpdated's shape:
 *   - dispatched on `user.{userId}` so every surface already wired into
 *     that firehose (Jobs/Index, SidebarJobs, Authenticate) can observe
 *     it without new channels
 *   - broadcasts inline (ShouldBroadcastNow) so a fast-follow relink →
 *     store → broadcast sequence arrives in order on the WS
 *
 * Payload carries `was_relink=true` + identifying fields; an INSERT
 * path would use status='ready' from the polling endpoint instead.
 */
class BexSessionRelinked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $bexSessionId,
        public string $environment,
        public ?string $accountEmail,
        public ?string $accountName,
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
        return 'bex-session-relinked';
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
            'account_name' => $this->accountName,
            'was_relink' => true,
            'at' => now()->toIso8601String(),
        ];
    }

    public static function fromSession(BexSession $session): self
    {
        return new self(
            userId: (int) $session->user_id,
            bexSessionId: (int) $session->id,
            environment: (string) $session->environment,
            accountEmail: $session->account_email,
            accountName: $session->account_name,
        );
    }
}
