<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed every few seconds by the scheduler so admins on the dashboard see
 * live CPU / memory / disk numbers without polling. ShouldBroadcastNow
 * (not the queued variant) keeps the latency floor at "as fast as Reverb
 * can flush" instead of "next queue tick".
 *
 * Single-tenant app, but we still guard the channel as private + admin-only
 * so non-admin users on a shared install never see host metrics.
 */
class ServerStatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $stats  ServerMetrics::snapshot() output
     */
    public function __construct(public array $stats) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server-stats')];
    }

    public function broadcastAs(): string
    {
        return 'server-stats-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->stats;
    }
}
