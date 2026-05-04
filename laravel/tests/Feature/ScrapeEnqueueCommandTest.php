<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the scheduler-side path. Three properties:
 *
 *   1. The guard gates inserts: a denied tick logs an info entry and
 *      counts toward `skipped`, with no exception bubbled.
 *   2. An allowed tick produces a fresh queued row whose params reflect
 *      the planner's two-mode window:
 *        - first/idle scrape  → catch-up window from `last_scraped_at - 30m`
 *        - sibling job active → slim window of `2 * job_spacing_minutes`
 *   3. Stale `last_scraped_at` doesn't accidentally suppress the new
 *      slim-window mode (the planner takes precedence once a sibling is
 *      running/queued).
 */
class ScrapeEnqueueCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $subscription;

    private BexSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'Cmd Test Org',
        ]);

        $bexApp = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Cmd Test App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $bexApp->id,
            'name' => 'Cmd Test Sub',
            'environment' => 'production',
            'auto_scrape' => true,
            'scrape_interval_minutes' => 5,
            'max_concurrent_jobs' => 1,
            'job_spacing_minutes' => 10,
            'max_pages_per_scrape' => 200,
            'max_duration_minutes' => 30,
            'lookback_days_first_scrape' => 7,
            'last_scraped_at' => now()->subHour(),
        ]);

        $this->session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);
    }

    public function test_allowed_tick_creates_a_queued_job_with_catch_up_window(): void
    {
        $this->artisan('scrape:enqueue')
            ->expectsOutputToContain('queued=1 skipped=0')
            ->assertSuccessful();

        $job = ScrapeJob::query()
            ->where('subscription_id', $this->subscription->id)
            ->firstOrFail();

        $this->assertSame(ScrapeJob::STATUS_QUEUED, $job->status);
        $this->assertNotNull($job->params);

        // No active sibling → catch-up window: last_scraped_at - 30m to now.
        $start = Carbon::parse((string) $job->params['start_time']);
        $expectedStart = $this->subscription->last_scraped_at->copy()->subMinutes(30);
        $this->assertEqualsWithDelta($expectedStart->timestamp, $start->timestamp, 5);

        $this->assertSame(200, $job->params['max_pages']);
        $this->assertSame(30, $job->params['max_duration_minutes']);
    }

    public function test_denied_tick_logs_info_and_skips_without_raising(): void
    {
        Log::spy();

        // Pre-seed a running job 30s ago — within the 10-min spacing window
        // and at the cap=1 ceiling.
        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subSeconds(30),
            'last_heartbeat_at' => now()->subSeconds(30),
        ]);

        $this->artisan('scrape:enqueue')
            ->expectsOutputToContain('queued=0 skipped=1')
            ->assertSuccessful();

        $this->assertSame(
            1,
            ScrapeJob::query()->where('subscription_id', $this->subscription->id)->count(),
            'No new job should be inserted when the guard denies.',
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $ctx) {
                return $message === 'scrape:enqueue gated by concurrency guard'
                    && ($ctx['reason'] ?? null) === 'concurrency_cap_reached';
            })
            ->once();
    }

    public function test_second_concurrent_job_uses_slim_spacing_window(): void
    {
        // Open the cap so a second concurrent job is permitted, and lock
        // "now" so we can assert the exact slim-window math.
        $this->subscription->update([
            'max_concurrent_jobs' => 2,
            'job_spacing_minutes' => 10,
            // Stale last_scraped_at — this should NOT leak into the slim
            // window; the planner detects the active sibling and switches
            // to the fixed 2x-spacing window instead.
            'last_scraped_at' => now()->subDays(2),
        ]);

        Carbon::setTestNow('2026-05-04T20:00:00Z');

        // Sibling job started 11m ago — outside the spacing window so the
        // guard says yes; the planner sees the sibling and goes slim.
        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => Carbon::parse('2026-05-04T19:49:00Z'),
            'last_heartbeat_at' => Carbon::parse('2026-05-04T19:49:00Z'),
        ]);

        $this->artisan('scrape:enqueue')
            ->expectsOutputToContain('queued=1 skipped=0')
            ->assertSuccessful();

        $second = ScrapeJob::query()
            ->where('subscription_id', $this->subscription->id)
            ->where('status', ScrapeJob::STATUS_QUEUED)
            ->firstOrFail();

        // Slim window: now - (2 * 10m) = 19:40:00Z to now (20:00:00Z).
        $start = Carbon::parse((string) $second->params['start_time']);
        $end = Carbon::parse((string) $second->params['end_time']);

        $this->assertSame('2026-05-04T19:40:00+00:00', $start->toIso8601String());
        $this->assertSame('2026-05-04T20:00:00+00:00', $end->toIso8601String());

        Carbon::setTestNow();
    }

    public function test_first_scrape_uses_lookback_days_when_no_last_scraped_at(): void
    {
        $this->subscription->update(['last_scraped_at' => null]);

        Carbon::setTestNow('2026-05-04T20:00:00Z');

        $this->artisan('scrape:enqueue')->assertSuccessful();

        $job = ScrapeJob::query()
            ->where('subscription_id', $this->subscription->id)
            ->firstOrFail();

        $start = Carbon::parse((string) $job->params['start_time']);
        $expected = Carbon::parse('2026-05-04T20:00:00Z')->subDays(7);

        $this->assertSame($expected->toIso8601String(), $start->toIso8601String());

        Carbon::setTestNow();
    }
}
