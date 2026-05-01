<?php

namespace App\Events;

use App\Models\ScrapeJob;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a scrape job changes lifecycle state or receives worker batch
 * progress. Used by the sidebar, the Jobs page, and any per-job UI elements
 * to update Inertia props or merge incremental `stats` from Echo.
 */
class ScrapeJobUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $jobId,
        public string $subscriptionId,
        public string $status,
        public ?array $stats = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
            new PrivateChannel('job.'.$this->jobId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'scrape-job-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payload = [
            'job_id' => $this->jobId,
            'subscription_id' => $this->subscriptionId,
            'status' => $this->status,
            'at' => now()->toIso8601String(),
        ];

        if ($this->stats !== null) {
            $payload['stats'] = $this->stats;
        }

        return $payload;
    }

    /**
     * Convenience constructor: derive (userId, subscriptionId, status) from a
     * ScrapeJob model. Caller must have eager-loaded the chain (or accept the
     * extra queries — fine for fail/complete which already happen rarely).
     */
    public static function fromJob(ScrapeJob $job): self
    {
        $userId = (int) ($job->subscription?->application?->organization?->user_id
            ?? \DB::table('subscriptions')
                ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
                ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
                ->where('subscriptions.id', $job->subscription_id)
                ->value('organizations.user_id'));

        return new self(
            userId: $userId,
            jobId: $job->id,
            subscriptionId: (string) $job->subscription_id,
            status: $job->status,
            stats: $job->stats,
        );
    }
}
