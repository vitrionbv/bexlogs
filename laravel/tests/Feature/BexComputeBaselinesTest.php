<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\SubscriptionBaseline;
use App\Models\User;
use App\Services\BaselineCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `bex:compute-baselines` reads the last 30 days of completed
 * scrape_jobs per subscription, computes percentiles for duration_ms
 * and rows_inserted, computes the per-hour rolling 7-day average for
 * rows_inserted, and upserts into `subscription_baselines`.
 *
 * The tests here pin three things:
 *
 *   1. The output line shape (`computed=N skipped=M`) matches the
 *      sibling commands so the schedule log stays scannable.
 *   2. Percentiles are computed via linear interpolation that matches
 *      Postgres's `percentile_cont` on the same input.
 *   3. Subscriptions with zero completed jobs in the window are
 *      skipped (no row created), and re-running the command updates
 *      the existing row instead of inserting a duplicate.
 */
class BexComputeBaselinesTest extends TestCase
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
            'id' => 'org-'.Str::random(6),
            'user_id' => $this->user->id,
            'name' => 'Baseline Test Org',
        ]);

        $app = Application::create([
            'id' => 'app-'.Str::random(6),
            'organization_id' => $org->id,
            'name' => 'Baseline Test App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-'.Str::random(6),
            'application_id' => $app->id,
            'name' => 'Baseline Test Sub',
            'environment' => 'production',
            'scrape_interval_minutes' => 5,
        ]);

        $this->session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);
    }

    public function test_command_persists_percentiles_and_returns_summary_line(): void
    {
        // Seed five completed jobs with deterministic durations so we
        // can assert exact percentile values. Linear-interpolated
        // percentiles on [100, 200, 300, 400, 500]:
        //   p50 = 300 (rank 2.0)
        //   p95 = 480 (rank 3.8 → 0.8 between 400 and 500)
        //   p99 = 496 (rank 3.96 → 0.96 between 400 and 500)
        $durations = [100, 200, 300, 400, 500];
        $now = Carbon::now();

        foreach ($durations as $i => $d) {
            ScrapeJob::create([
                'subscription_id' => $this->subscription->id,
                'bex_session_id' => $this->session->id,
                'status' => ScrapeJob::STATUS_COMPLETED,
                'started_at' => $now->copy()->subDays($i + 1)->subMilliseconds($d),
                'completed_at' => $now->copy()->subDays($i + 1),
                'stats' => ['rows_inserted' => 50, 'duration_ms' => $d],
            ]);
        }

        $exitCode = $this->artisan('bex:compute-baselines')
            ->expectsOutputToContain('computed=1 skipped=0')
            ->run();

        $this->assertSame(0, $exitCode);

        $baseline = SubscriptionBaseline::query()
            ->where('subscription_id', $this->subscription->id)
            ->first();

        $this->assertNotNull($baseline);
        $this->assertEqualsWithDelta(300.0, $baseline->duration_p50, 0.1);
        $this->assertEqualsWithDelta(480.0, $baseline->duration_p95, 0.1);
        $this->assertEqualsWithDelta(496.0, $baseline->duration_p99, 0.1);
        $this->assertSame(5, $baseline->sample_size);
        $this->assertNotNull($baseline->computed_at);
    }

    public function test_subscription_with_no_jobs_is_skipped(): void
    {
        // No scrape_jobs for this->subscription → skip count = 1, no
        // SubscriptionBaseline row created.
        $this->artisan('bex:compute-baselines')
            ->expectsOutputToContain('computed=0 skipped=1')
            ->assertExitCode(0);

        $this->assertSame(
            0,
            SubscriptionBaseline::query()
                ->where('subscription_id', $this->subscription->id)
                ->count(),
        );
    }

    public function test_rerun_updates_existing_row_in_place(): void
    {
        // Seed one job, run the command once. Then add three more
        // jobs and re-run — the row must be UPDATED, not duplicated.
        // The unique index on subscription_id enforces this at the
        // DB level, but we also want to assert the calculator's
        // updateOrCreate path is actually taking the update branch.
        $this->seedCompleted(daysAgo: 2, durationMs: 100, rowsInserted: 10);

        $this->artisan('bex:compute-baselines')->assertExitCode(0);

        $first = SubscriptionBaseline::query()
            ->where('subscription_id', $this->subscription->id)
            ->first();
        $this->assertNotNull($first);
        $this->assertSame(1, $first->sample_size);

        $this->seedCompleted(daysAgo: 3, durationMs: 200, rowsInserted: 20);
        $this->seedCompleted(daysAgo: 4, durationMs: 300, rowsInserted: 30);
        $this->seedCompleted(daysAgo: 5, durationMs: 400, rowsInserted: 40);

        $this->artisan('bex:compute-baselines')->assertExitCode(0);

        $rows = SubscriptionBaseline::query()
            ->where('subscription_id', $this->subscription->id)
            ->get();
        $this->assertCount(1, $rows, 'Re-running the command must not duplicate the baseline row.');
        $this->assertSame(4, $rows->first()->sample_size);
    }

    public function test_same_hour_avg_built_from_recent_seven_days_only(): void
    {
        // The same-hour rolling average uses the last 7 days only,
        // even though the percentile window is 30. Seed two jobs in
        // the same UTC hour: one inside the 7-day window, one outside.
        // Only the recent one should contribute to the per-hour map.
        $hour = 12; // arbitrary
        $insideWindow = Carbon::now()->setTime($hour, 0, 0)->subDays(2);
        $outsideWindow = Carbon::now()->setTime($hour, 0, 0)->subDays(20);

        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_COMPLETED,
            'started_at' => $insideWindow->copy()->subSeconds(10),
            'completed_at' => $insideWindow,
            'stats' => ['rows_inserted' => 50, 'duration_ms' => 1000],
        ]);

        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_COMPLETED,
            'started_at' => $outsideWindow->copy()->subSeconds(10),
            'completed_at' => $outsideWindow,
            'stats' => ['rows_inserted' => 9999, 'duration_ms' => 1000],
        ]);

        $this->artisan('bex:compute-baselines')->assertExitCode(0);

        $baseline = SubscriptionBaseline::query()
            ->where('subscription_id', $this->subscription->id)
            ->first();
        $this->assertNotNull($baseline);

        $hourKey = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
        $map = $baseline->same_hour_avg_rows_inserted ?? [];

        $this->assertArrayHasKey($hourKey, $map);
        // 9999 from the outside-window job must NOT be in the average.
        $this->assertEqualsWithDelta(50.0, (float) $map[$hourKey], 0.1);
    }

    public function test_calculator_percentile_helper_matches_postgres_behaviour(): void
    {
        // Lock in the exact percentile_cont semantics in unit form so
        // a future refactor that swaps SQL for raw PG calls can verify
        // the output is byte-for-byte identical.
        $calc = new BaselineCalculator;

        $this->assertEqualsWithDelta(2.5, $calc->percentile([1, 2, 3, 4], 0.5), 1e-9);
        $this->assertEqualsWithDelta(1.0, $calc->percentile([1, 2, 3, 4], 0.0), 1e-9);
        $this->assertEqualsWithDelta(4.0, $calc->percentile([1, 2, 3, 4], 1.0), 1e-9);
        $this->assertNull($calc->percentile([], 0.5));
        $this->assertEqualsWithDelta(7.0, $calc->percentile([7], 0.95), 1e-9);
    }

    private function seedCompleted(int $daysAgo, int $durationMs, int $rowsInserted): void
    {
        $completed = Carbon::now()->subDays($daysAgo);

        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_COMPLETED,
            'started_at' => $completed->copy()->subMilliseconds($durationMs),
            'completed_at' => $completed,
            'stats' => ['rows_inserted' => $rowsInserted, 'duration_ms' => $durationMs],
        ]);
    }
}
