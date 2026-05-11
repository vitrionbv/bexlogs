<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins the HealthScoreCalculator's three signals + composite formula.
 * Each test isolates one component (success_rate, freshness, stability)
 * and asserts the label / score behaviour at the boundaries:
 *
 *   - `healthy`    composite >= 0.8
 *   - `degraded`   0.4 ..= 0.8
 *   - `unhealthy`  < 0.4
 *
 * The formula itself: 0.5*success + 0.3*freshness + 0.2*stability.
 */
class HealthScoreCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $subscription;

    private HealthScoreCalculator $calc;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(6),
            'user_id' => $user->id,
            'name' => 'Health Test Org',
        ]);

        $app = Application::create([
            'id' => 'app-'.Str::random(6),
            'organization_id' => $org->id,
            'name' => 'Health Test App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-'.Str::random(6),
            'application_id' => $app->id,
            'name' => 'Health Test Sub',
            'environment' => 'production',
            'scrape_interval_minutes' => 5,
        ]);

        $session = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);
        $this->sessionId = $session->id;

        $this->calc = new HealthScoreCalculator;
    }

    public function test_empty_history_yields_neutral_degraded(): void
    {
        $result = $this->calc->forSubscription($this->subscription);

        $this->assertSame(HealthScoreCalculator::LABEL_DEGRADED, $result['label']);
        $this->assertSame(0, $result['sample_size']);
        $this->assertNull($result['last_success_at']);
    }

    public function test_all_completed_recent_steady_yields_healthy(): void
    {
        // Five completions, each ~3 minutes apart, all with similar
        // rows_inserted. Last completion is 1 minute ago — well
        // inside the 5 min interval.
        $now = Carbon::now();
        $rows = [120, 121, 119, 122, 120];

        foreach ($rows as $i => $count) {
            ScrapeJob::create([
                'subscription_id' => $this->subscription->id,
                'bex_session_id' => $this->fakeSession(),
                'status' => ScrapeJob::STATUS_COMPLETED,
                'started_at' => $now->copy()->subMinutes(($i + 1) * 3 + 1),
                'completed_at' => $now->copy()->subMinutes(($i + 1) * 3),
                'stats' => ['rows_inserted' => $count, 'duration_ms' => 1000],
            ]);
        }

        // Newest row first — but model order doesn't matter here, the
        // calculator uses orderByDesc('id'). Insert one more very
        // recent run so freshness=1.0.
        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->fakeSession(),
            'status' => ScrapeJob::STATUS_COMPLETED,
            'started_at' => $now->copy()->subMinute(),
            'completed_at' => $now->copy()->subSeconds(30),
            'stats' => ['rows_inserted' => 121, 'duration_ms' => 950],
        ]);

        $result = $this->calc->forSubscription($this->subscription);

        $this->assertSame(HealthScoreCalculator::LABEL_HEALTHY, $result['label']);
        $this->assertGreaterThanOrEqual(0.8, $result['score']);
        $this->assertSame(1.0, $result['components']['success_rate']);
        $this->assertSame(1.0, $result['components']['freshness']);
        // CV of nearly-identical inputs ~ 0 → stability close to 1.
        $this->assertGreaterThan(0.9, $result['components']['stability']);
    }

    public function test_all_failures_yield_unhealthy(): void
    {
        $now = Carbon::now();

        for ($i = 0; $i < 6; $i++) {
            ScrapeJob::create([
                'subscription_id' => $this->subscription->id,
                'bex_session_id' => $this->fakeSession(),
                'status' => ScrapeJob::STATUS_FAILED,
                'started_at' => $now->copy()->subMinutes(($i + 1) * 5),
                'completed_at' => $now->copy()->subMinutes(($i + 1) * 5)->addSeconds(10),
                'error' => 'boom',
                'stats' => null,
            ]);
        }

        $result = $this->calc->forSubscription($this->subscription);

        $this->assertSame(HealthScoreCalculator::LABEL_UNHEALTHY, $result['label']);
        $this->assertLessThan(HealthScoreCalculator::DEGRADED_THRESHOLD, $result['score']);
        $this->assertSame(0.0, $result['components']['success_rate']);
        $this->assertSame(0.0, $result['components']['freshness']);
    }

    public function test_freshness_falls_off_past_interval(): void
    {
        // One completion long ago — well past 3× the 5-min interval.
        // Freshness should be 0.0; success_rate 1.0 still drags the
        // composite up but the freshness collapse alone moves us out
        // of "healthy".
        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->fakeSession(),
            'status' => ScrapeJob::STATUS_COMPLETED,
            'started_at' => Carbon::now()->subHour(),
            'completed_at' => Carbon::now()->subHour()->addSeconds(10),
            'stats' => ['rows_inserted' => 50, 'duration_ms' => 1000],
        ]);

        $result = $this->calc->forSubscription($this->subscription);

        $this->assertSame(0.0, $result['components']['freshness']);
        // 0.5*1 + 0.3*0 + 0.2*0.5 (single sample → neutral stability) = 0.6
        $this->assertEqualsWithDelta(0.6, $result['score'], 0.05);
        $this->assertSame(HealthScoreCalculator::LABEL_DEGRADED, $result['label']);
    }

    public function test_volatile_inserts_lower_stability(): void
    {
        // High CV: 1, 100, 1, 100, ... Mean=50.5, stddev≈49.5 → CV
        // ≈ 0.98 → stability ≈ 0.02. Stays "degraded" via the
        // freshness collapse and stability drop, but success_rate is
        // 1.0 so we're well above unhealthy.
        $now = Carbon::now();
        foreach ([1, 100, 1, 100, 1, 100] as $i => $count) {
            ScrapeJob::create([
                'subscription_id' => $this->subscription->id,
                'bex_session_id' => $this->fakeSession(),
                'status' => ScrapeJob::STATUS_COMPLETED,
                'started_at' => $now->copy()->subMinutes(($i + 1) * 7),
                'completed_at' => $now->copy()->subMinutes(($i + 1) * 7)->addSeconds(20),
                'stats' => ['rows_inserted' => $count, 'duration_ms' => 1000],
            ]);
        }

        $result = $this->calc->forSubscription($this->subscription);

        $this->assertLessThan(0.1, $result['components']['stability']);
        $this->assertSame(1.0, $result['components']['success_rate']);
    }

    public function test_label_static_is_inclusive_at_thresholds(): void
    {
        // Boundaries are inclusive on the upper side: 0.8 = healthy,
        // 0.4 = degraded. 0.39999 = unhealthy. The Manage UI maps
        // these to dot colours, so locking in the inclusive edge
        // prevents a tiny rounding-induced colour flicker when a
        // score sits right on the boundary.
        $this->assertSame('healthy', HealthScoreCalculator::label(0.8));
        $this->assertSame('healthy', HealthScoreCalculator::label(0.95));
        $this->assertSame('degraded', HealthScoreCalculator::label(0.4));
        $this->assertSame('degraded', HealthScoreCalculator::label(0.79));
        $this->assertSame('unhealthy', HealthScoreCalculator::label(0.39));
        $this->assertSame('unhealthy', HealthScoreCalculator::label(0.0));
    }

    /**
     * Cheap stub session so the FK on scrape_jobs.bex_session_id is
     * satisfied without dragging the BookingExperts auth flow into a
     * pure-arithmetic test.
     */
    private function fakeSession(): int
    {
        return $this->sessionId;
    }
}
