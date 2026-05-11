<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\WorkerController;
use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\SubscriptionBaseline;
use App\Models\User;
use App\Services\AnomalyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the three drift rules in AnomalyDetector against curated job
 * fixtures + a hand-written baseline. Each test isolates one rule so
 * a future bug in one rule doesn't cascade through the others.
 *
 * The detector's contract is "pure": never write, never broadcast.
 * We only assert on the returned signals.
 */
class AnomalyDetectorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $subscription;

    private BexSession $session;

    private AnomalyDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(6),
            'user_id' => $this->user->id,
            'name' => 'Anomaly Test Org',
        ]);

        $app = Application::create([
            'id' => 'app-'.Str::random(6),
            'organization_id' => $org->id,
            'name' => 'Anomaly Test App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-'.Str::random(6),
            'application_id' => $app->id,
            'name' => 'Anomaly Test Sub',
            'environment' => 'production',
            'scrape_interval_minutes' => 5,
        ]);

        $this->session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);

        $this->detector = new AnomalyDetector;
    }

    public function test_no_jobs_yields_no_signals(): void
    {
        $signals = $this->detector->forUser($this->user->id);

        $this->assertSame([], $signals);
    }

    public function test_duration_spike_detected_against_baseline_p95(): void
    {
        // Baseline p95 = 1000ms, latest job took 6000ms (> 5×). We
        // also stick a couple of "normal" jobs in front of the spike
        // so the rule has data to fall back to but the detector still
        // picks the latest one for comparison.
        $this->seedBaseline(['duration_p95' => 1000.0]);

        $now = Carbon::now();
        $this->seedCompleted(
            offsetMinutes: 30,
            durationMs: 900,
            rowsInserted: 100,
        );
        $this->seedCompleted(
            offsetMinutes: 15,
            durationMs: 950,
            rowsInserted: 100,
        );
        // The spike — most recent completion.
        $this->seedCompleted(
            offsetMinutes: 1,
            durationMs: 6000,
            rowsInserted: 100,
        );

        $signals = collect($this->detector->forUser($this->user->id));

        $this->assertTrue(
            $signals->contains(fn (array $s) => $s['kind'] === AnomalyDetector::KIND_DURATION_SPIKE),
            'Expected a duration_spike signal when latest duration is over 5× p95.',
        );
    }

    public function test_no_signal_when_baseline_is_stale(): void
    {
        // Same fixture as the spike test — but the baseline is older
        // than 36h, so the detector should suppress the rule.
        $this->seedBaseline([
            'duration_p95' => 1000.0,
            'computed_at' => Carbon::now()->subHours(48),
        ]);

        $this->seedCompleted(offsetMinutes: 1, durationMs: 6000, rowsInserted: 100);

        $signals = collect($this->detector->forUser($this->user->id));

        $this->assertFalse(
            $signals->contains(fn (array $s) => $s['kind'] === AnomalyDetector::KIND_DURATION_SPIKE),
        );
    }

    public function test_insert_rate_drop_detected_against_same_hour_average(): void
    {
        // Synthesise a same-hour table where the hour we're about to
        // complete in expects ~1000 rows but the latest job inserted
        // 5. Rule fires.
        $latestCompletedAt = Carbon::now()->subMinute();
        $hourKey = str_pad((string) $latestCompletedAt->hour, 2, '0', STR_PAD_LEFT);

        $this->seedBaseline([
            'duration_p95' => null, // Disable the duration rule for clarity.
            'same_hour_avg_rows_inserted' => [$hourKey => 1000.0],
        ]);

        $this->seedCompleted(
            offsetMinutes: 1,
            durationMs: 800,
            rowsInserted: 5,
        );

        $signals = collect($this->detector->forUser($this->user->id));

        $this->assertTrue(
            $signals->contains(fn (array $s) => $s['kind'] === AnomalyDetector::KIND_INSERT_RATE_DROP),
        );
    }

    public function test_stop_reason_regression_detected_when_last_three_worsen(): void
    {
        // Three completed runs in chronological order: caught_up (1) →
        // pagination_limit (3) → pagination_error (4). The last is
        // strictly worse than the first AND the sequence is monotonic
        // → the rule fires.
        $this->seedCompleted(offsetMinutes: 30, durationMs: 800, rowsInserted: 100, stopReason: 'caught_up');
        $this->seedCompleted(offsetMinutes: 20, durationMs: 850, rowsInserted: 100, stopReason: 'pagination_limit');
        $this->seedCompleted(offsetMinutes: 5, durationMs: 900, rowsInserted: 100, stopReason: 'pagination_error');

        $signals = collect($this->detector->forUser($this->user->id));

        $this->assertTrue(
            $signals->contains(fn (array $s) => $s['kind'] === AnomalyDetector::KIND_STOP_REASON_REGRESS),
        );
    }

    public function test_stop_reason_regression_not_triggered_on_improving_sequence(): void
    {
        // Three completed runs: pagination_error (4) → caught_up (1) →
        // duplicate_detection (0). Sequence is improving — should NOT
        // trigger the regression rule.
        $this->seedCompleted(offsetMinutes: 30, durationMs: 800, rowsInserted: 100, stopReason: 'pagination_error');
        $this->seedCompleted(offsetMinutes: 20, durationMs: 850, rowsInserted: 100, stopReason: 'caught_up');
        $this->seedCompleted(offsetMinutes: 5, durationMs: 900, rowsInserted: 100, stopReason: 'duplicate_detection');

        $signals = collect($this->detector->forUser($this->user->id));

        $this->assertFalse(
            $signals->contains(fn (array $s) => $s['kind'] === AnomalyDetector::KIND_STOP_REASON_REGRESS),
        );
    }

    public function test_three_consecutive_failures_with_same_bad_reason_trigger_regression(): void
    {
        // Even when the rank is constant, three runs landing on the
        // same FAIL-class reason in a row is its own signal. This is
        // the "stuck" case — e.g. session expired three times running.
        $this->seedCompleted(offsetMinutes: 30, durationMs: 800, rowsInserted: 0, stopReason: 'session_expired');
        $this->seedCompleted(offsetMinutes: 20, durationMs: 850, rowsInserted: 0, stopReason: 'session_expired');
        $this->seedCompleted(offsetMinutes: 5, durationMs: 900, rowsInserted: 0, stopReason: 'session_expired');

        $signals = collect($this->detector->forUser($this->user->id));

        $this->assertTrue(
            $signals->contains(fn (array $s) => $s['kind'] === AnomalyDetector::KIND_STOP_REASON_REGRESS),
        );
    }

    public function test_stop_reason_rank_table_covers_every_worker_constant(): void
    {
        // Sanity check: if WorkerController::STOP_REASONS gains a new
        // entry, the rank table here MUST be updated to cover it.
        // Detector returns the missing keys; an empty result means
        // we're in sync.
        $this->assertSame(
            [],
            AnomalyDetector::rankCoverage(),
            'AnomalyDetector::STOP_REASON_RANK is missing entries for: '
                .implode(', ', AnomalyDetector::rankCoverage()),
        );

        // Also confirm the symmetric direction — every key we have
        // ranked should still exist in the worker constant.
        $orphans = array_diff(
            array_keys(AnomalyDetector::stopReasonRanks()),
            WorkerController::STOP_REASONS,
        );
        $this->assertSame(
            [],
            $orphans,
            'AnomalyDetector ranks contain reasons not in WorkerController::STOP_REASONS: '.implode(', ', $orphans),
        );
    }

    /**
     * Seed a SubscriptionBaseline row for the test subscription. Any
     * key not provided defaults to a sensible "normal" value so the
     * test only has to override the fields it cares about.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedBaseline(array $overrides = []): void
    {
        SubscriptionBaseline::create(array_merge([
            'subscription_id' => $this->subscription->id,
            'duration_p50' => 500.0,
            'duration_p95' => 1000.0,
            'duration_p99' => 2000.0,
            'rows_inserted_p50' => 100.0,
            'rows_inserted_p95' => 200.0,
            'rows_inserted_p99' => 300.0,
            'same_hour_avg_rows_inserted' => null,
            'sample_size' => 50,
            'window_from' => Carbon::now()->subDays(30),
            'window_to' => Carbon::now(),
            'computed_at' => Carbon::now(),
        ], $overrides));
    }

    /**
     * Seed one completed scrape job. `offsetMinutes` is the number of
     * minutes ago the run completed. The created_at is set in lockstep
     * with completed_at so DESC id ordering matches DESC chronological
     * ordering — the detector relies on `orderByDesc('id')`.
     */
    private function seedCompleted(
        int $offsetMinutes,
        int $durationMs,
        int $rowsInserted,
        ?string $stopReason = null,
    ): ScrapeJob {
        $completed = Carbon::now()->subMinutes($offsetMinutes);
        $started = $completed->copy()->subMilliseconds($durationMs);

        $stats = [
            'rows_inserted' => $rowsInserted,
            'rows_received' => $rowsInserted,
            'duration_ms' => $durationMs,
        ];

        if ($stopReason !== null) {
            $stats['stop_reason'] = $stopReason;
        }

        return ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_COMPLETED,
            'started_at' => $started,
            'completed_at' => $completed,
            'stats' => $stats,
        ]);
    }
}
