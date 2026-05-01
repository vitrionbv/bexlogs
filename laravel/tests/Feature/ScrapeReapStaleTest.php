<?php

namespace Tests\Feature;

use App\Events\ScrapeJobUpdated;
use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the `scrape:reap-stale` command. Five scenarios:
 *
 *   A. running + heartbeat 10m old              → reaped (failed)
 *   B. running + heartbeat 30s old              → untouched
 *   C. running + heartbeat null + started 10m   → reaped (started_at fallback)
 *   D. queued                                   → untouched
 *   E. completed/failed                         → untouched
 *
 * `Event::fake([ScrapeJobUpdated::class])` lets us assert exact dispatch
 * counts. The reaper uses `broadcast(...)` (matching the rest of the app);
 * that goes through the event dispatcher under the hood, so the fake
 * captures it cleanly.
 */
class ScrapeReapStaleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    // `$app` would shadow Illuminate\Foundation\Testing\TestCase::$app.
    private Application $bexApp;

    private Subscription $subscription;

    private BexSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'Reaper Test Org',
        ]);

        $this->bexApp = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $this->org->id,
            'name' => 'Reaper Test App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $this->bexApp->id,
            'name' => 'Reaper Test Sub',
            'environment' => 'production',
        ]);

        $this->session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);
    }

    public function test_running_job_with_stale_heartbeat_is_reaped(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        $job = $this->makeJob([
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(15),
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $this->artisan('scrape:reap-stale')
            ->expectsOutputToContain('reaped=1')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $job->status);
        $this->assertNotNull($job->completed_at);
        $this->assertSame(
            'Worker did not send a heartbeat for over 3 minutes; job reaped as stale.',
            $job->error,
        );
        $this->assertSame('worker_reaped', $job->stats['stop_reason'] ?? null);

        Event::assertDispatched(ScrapeJobUpdated::class, 1);
        Event::assertDispatched(
            ScrapeJobUpdated::class,
            fn (ScrapeJobUpdated $e) => $e->jobId === $job->id
                && $e->status === ScrapeJob::STATUS_FAILED
                && $e->subscriptionId === (string) $this->subscription->id,
        );
    }

    public function test_reaper_preserves_existing_stats_when_stamping_stop_reason(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        $job = $this->makeJob([
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(15),
            'last_heartbeat_at' => now()->subMinutes(10),
            'stats' => [
                'rows_received' => 50,
                'rows_inserted' => 48,
                'batches' => 5,
                'pages_processed' => 3,
            ],
        ]);

        $this->artisan('scrape:reap-stale')->assertSuccessful();

        $job->refresh();
        $stats = $job->stats ?? [];

        $this->assertSame('worker_reaped', $stats['stop_reason'] ?? null);
        $this->assertSame(50, (int) ($stats['rows_received'] ?? 0));
        $this->assertSame(48, (int) ($stats['rows_inserted'] ?? 0));
        $this->assertSame(5, (int) ($stats['batches'] ?? 0));
        $this->assertSame(3, (int) ($stats['pages_processed'] ?? 0));
    }

    public function test_running_job_with_recent_heartbeat_is_left_alone(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        $job = $this->makeJob([
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(2),
            'last_heartbeat_at' => now()->subSeconds(30),
        ]);

        $this->artisan('scrape:reap-stale')
            ->expectsOutputToContain('reaped=0')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(ScrapeJob::STATUS_RUNNING, $job->status);
        $this->assertNull($job->completed_at);
        $this->assertNull($job->error);

        Event::assertNotDispatched(ScrapeJobUpdated::class);
    }

    public function test_running_job_with_null_heartbeat_but_old_started_at_is_reaped(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        $job = $this->makeJob([
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(10),
            'last_heartbeat_at' => null,
        ]);

        $this->artisan('scrape:reap-stale')
            ->expectsOutputToContain('reaped=1')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $job->status);
        $this->assertStringContainsString('reaped as stale', (string) $job->error);

        Event::assertDispatched(ScrapeJobUpdated::class, 1);
    }

    public function test_queued_job_is_never_reaped(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        $job = $this->makeJob([
            'status' => ScrapeJob::STATUS_QUEUED,
            'started_at' => null,
            'last_heartbeat_at' => null,
        ]);

        $this->artisan('scrape:reap-stale')
            ->expectsOutputToContain('reaped=0')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(ScrapeJob::STATUS_QUEUED, $job->status);
        Event::assertNotDispatched(ScrapeJobUpdated::class);
    }

    public function test_completed_and_failed_jobs_are_never_touched(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        $completed = $this->makeJob([
            'status' => ScrapeJob::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(20),
            'last_heartbeat_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(15),
        ]);

        $failed = $this->makeJob([
            'status' => ScrapeJob::STATUS_FAILED,
            'started_at' => now()->subMinutes(20),
            'last_heartbeat_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(15),
            'error' => 'something else',
        ]);

        $this->artisan('scrape:reap-stale')
            ->expectsOutputToContain('reaped=0')
            ->assertSuccessful();

        $completed->refresh();
        $failed->refresh();

        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $completed->status);
        $this->assertSame(ScrapeJob::STATUS_FAILED, $failed->status);
        $this->assertSame('something else', $failed->error);

        Event::assertNotDispatched(ScrapeJobUpdated::class);
    }

    public function test_running_twice_back_to_back_is_idempotent(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        $job = $this->makeJob([
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(15),
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $this->artisan('scrape:reap-stale')->assertSuccessful();
        $this->artisan('scrape:reap-stale')
            ->expectsOutputToContain('reaped=0')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $job->status);
        Event::assertDispatched(ScrapeJobUpdated::class, 1);
    }

    public function test_threshold_is_configurable_via_minutes_option(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        // 90 seconds old: stale at --minutes=1, but fresh at --minutes=3.
        $job = $this->makeJob([
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(2),
            'last_heartbeat_at' => now()->subSeconds(90),
        ]);

        $this->artisan('scrape:reap-stale', ['--minutes' => 3])
            ->expectsOutputToContain('reaped=0')
            ->assertSuccessful();
        $this->assertSame(ScrapeJob::STATUS_RUNNING, $job->fresh()->status);

        $this->artisan('scrape:reap-stale', ['--minutes' => 1])
            ->expectsOutputToContain('reaped=1')
            ->assertSuccessful();

        $job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $job->status);
        $this->assertSame(
            'Worker did not send a heartbeat for over 1 minutes; job reaped as stale.',
            $job->error,
        );
    }

    public function test_logs_each_reaped_job_at_info(): void
    {
        Event::fake([ScrapeJobUpdated::class]);
        Log::spy();

        $job = $this->makeJob([
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(15),
            'last_heartbeat_at' => now()->subMinutes(10),
        ]);

        $this->artisan('scrape:reap-stale')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($job) {
                return $message === 'scrape:reap-stale reaped job'
                    && $context['job_id'] === $job->id
                    && $context['subscription_id'] === $this->subscription->id;
            })
            ->once();
    }

    /** @param array<string, mixed> $overrides */
    private function makeJob(array $overrides = []): ScrapeJob
    {
        return ScrapeJob::create(array_merge([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_QUEUED,
            'attempts' => 0,
        ], $overrides));
    }
}
