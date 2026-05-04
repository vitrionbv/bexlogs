<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ScrapeEnqueueGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the per-subscription concurrency contract enforced by
 * `ScrapeEnqueueGuard`. The matrix:
 *
 *   - no active job                                          → allowed
 *   - 1 running, cap=1                                       → denied (cap)
 *   - 1 running 30s ago, cap=3, spacing=10m                  → denied (spacing, ~570s left)
 *   - 1 running 11m ago, cap=3, spacing=10m                  → allowed
 *   - 1 queued (not started)                                 → denied (queued, not started)
 *   - 3 running + 1 queued, cap=3                            → denied (cap, regardless of spacing)
 *   - 1 running 11m ago, cap=2, spacing=10m                  → allowed (second concurrent)
 *
 * Together these keep the operator-visible knobs (max_concurrent_jobs,
 * job_spacing_minutes) honest without leaning on a DB-level constraint.
 */
class ScrapeEnqueueGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $subscription;

    private BexSession $session;

    private ScrapeEnqueueGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'Guard Test Org',
        ]);

        $bexApp = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Guard Test App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $bexApp->id,
            'name' => 'Guard Test Sub',
            'environment' => 'production',
            'max_concurrent_jobs' => 1,
            'job_spacing_minutes' => 10,
        ]);

        $this->session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);

        $this->guard = new ScrapeEnqueueGuard;
    }

    public function test_no_active_job_is_allowed(): void
    {
        $decision = $this->guard->mayEnqueue($this->subscription);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
        $this->assertNull($decision->retryAfterSeconds);
    }

    public function test_one_running_with_cap_one_denies_with_concurrency_cap_reason(): void
    {
        $this->makeRunningJob(startedMinutesAgo: 1);

        $decision = $this->guard->mayEnqueue($this->subscription);

        $this->assertFalse($decision->allowed);
        $this->assertSame('concurrency_cap_reached', $decision->reason);
        $this->assertSame(60, $decision->retryAfterSeconds);
    }

    public function test_one_running_within_spacing_window_denies_with_spacing_reason(): void
    {
        $this->subscription->update(['max_concurrent_jobs' => 3, 'job_spacing_minutes' => 10]);

        Carbon::setTestNow('2026-05-04T20:00:30Z');
        $this->makeRunningJob(startedAt: Carbon::parse('2026-05-04T20:00:00Z'));

        $decision = $this->guard->mayEnqueue($this->subscription);

        $this->assertFalse($decision->allowed);
        $this->assertSame('within_spacing_window', $decision->reason);
        // 10m - 30s = 570s, give or take rounding for "now"
        $this->assertNotNull($decision->retryAfterSeconds);
        $this->assertGreaterThan(560, $decision->retryAfterSeconds);
        $this->assertLessThanOrEqual(600, $decision->retryAfterSeconds);

        Carbon::setTestNow();
    }

    public function test_one_running_outside_spacing_window_is_allowed(): void
    {
        $this->subscription->update(['max_concurrent_jobs' => 3, 'job_spacing_minutes' => 10]);

        $this->makeRunningJob(startedMinutesAgo: 11);

        $decision = $this->guard->mayEnqueue($this->subscription);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
    }

    public function test_queued_but_not_started_denies_with_prior_job_not_yet_started(): void
    {
        $this->subscription->update(['max_concurrent_jobs' => 3, 'job_spacing_minutes' => 10]);

        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_QUEUED,
        ]);

        $decision = $this->guard->mayEnqueue($this->subscription);

        $this->assertFalse($decision->allowed);
        $this->assertSame('prior_job_not_yet_started', $decision->reason);
        $this->assertSame(30, $decision->retryAfterSeconds);
    }

    public function test_three_running_plus_one_queued_with_cap_three_denies_for_cap(): void
    {
        $this->subscription->update(['max_concurrent_jobs' => 3, 'job_spacing_minutes' => 1]);

        $this->makeRunningJob(startedMinutesAgo: 30);
        $this->makeRunningJob(startedMinutesAgo: 20);
        $this->makeRunningJob(startedMinutesAgo: 11);
        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_QUEUED,
        ]);

        $decision = $this->guard->mayEnqueue($this->subscription);

        $this->assertFalse($decision->allowed);
        $this->assertSame(
            'concurrency_cap_reached',
            $decision->reason,
            'Cap takes precedence over spacing — even if spacing met, four active jobs > cap=3.',
        );
    }

    public function test_second_concurrent_is_allowed_when_cap_two_and_spacing_met(): void
    {
        $this->subscription->update(['max_concurrent_jobs' => 2, 'job_spacing_minutes' => 10]);

        $this->makeRunningJob(startedMinutesAgo: 11);

        $decision = $this->guard->mayEnqueue($this->subscription);

        $this->assertTrue($decision->allowed);
    }

    public function test_completed_jobs_do_not_count_against_cap_or_spacing(): void
    {
        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinutes(1),
        ]);

        $decision = $this->guard->mayEnqueue($this->subscription);

        $this->assertTrue(
            $decision->allowed,
            'A completed job from 2 minutes ago must not block enqueue even with default 10-min spacing.',
        );
    }

    private function makeRunningJob(?int $startedMinutesAgo = null, ?Carbon $startedAt = null): ScrapeJob
    {
        $startedAt ??= now()->subMinutes($startedMinutesAgo ?? 1);

        return ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => $startedAt,
            'last_heartbeat_at' => $startedAt,
        ]);
    }
}
