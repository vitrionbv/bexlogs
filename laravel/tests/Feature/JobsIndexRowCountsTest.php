<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Regression coverage for the Jobs UI row-count contract. Before the
 * fix, the table read `stats.rows` — a legacy counter the scraper
 * emitted as `pages_processed × BATCH_SIZE`, which gave operators
 * suspiciously round numbers (150, 100, 125 = 6/4/5 pages × 25)
 * instead of real row counts. The fix swaps the surface to
 * `rows_received` (post in-batch dedup) and `rows_inserted` (post
 * Postgres unique-index dedup), with `total_duplicates` rendered
 * alongside in the detail dialog.
 *
 * The Inertia-side contract: the controller hands the raw `stats`
 * blob through unchanged, and the Vue page reads the three named
 * fields off that blob. So the assertion target is "the named fields
 * survive the controller's `through()` projection unmodified" — i.e.
 * we don't need a Vue snapshot test (no Vitest harness is configured
 * in this repo) because the controller is the only Laravel-side
 * gatekeeper between `scrape_jobs.stats` and what the page renders.
 *
 * The companion `WorkerStopReasonTest::test_complete_drops_legacy_rows_field_at_merge…`
 * proves the WRITE side (validator strips `rows`); this file proves
 * the READ side (controller surfaces the new triplet).
 */
class JobsIndexRowCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_index_passes_rows_received_and_rows_inserted_through_inertia_props(): void
    {
        $job = $this->makeJob([
            'rows_received' => 150,
            'rows_inserted' => 142,
            'total_duplicates' => 8,
            'pages' => 6,
            'pages_processed' => 6,
            'batches' => 6,
            'duration_ms' => 24_500,
            'stop_reason' => 'duplicate_detection',
        ]);

        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertInertia(function (AssertableInertia $page) use ($job) {
                $page
                    ->component('Jobs/Index')
                    ->where('jobs.data.0.id', $job->id)
                    ->where('jobs.data.0.stats.rows_received', 150)
                    ->where('jobs.data.0.stats.rows_inserted', 142)
                    ->where('jobs.data.0.stats.total_duplicates', 8)
                    ->where('jobs.data.0.stats.pages', 6);
            });
    }

    public function test_index_propagates_stats_for_running_jobs_with_partial_counters(): void
    {
        $job = $this->makeJob([
            'rows_received' => 75,
            'rows_inserted' => 75,
            'total_duplicates' => 0,
            'batches' => 3,
            'pages_processed' => 3,
        ], status: ScrapeJob::STATUS_RUNNING);

        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertInertia(function (AssertableInertia $page) use ($job) {
                $page
                    ->where('jobs.data.0.id', $job->id)
                    ->where('jobs.data.0.status', ScrapeJob::STATUS_RUNNING)
                    ->where('jobs.data.0.stats.rows_received', 75)
                    ->where('jobs.data.0.stats.rows_inserted', 75)
                    ->where('jobs.data.0.stats.total_duplicates', 0);
            });
    }

    public function test_index_does_not_inject_a_legacy_rows_field_for_jobs_that_never_carried_one(): void
    {
        // A fresh job persisted with only the new triplet must NOT
        // grow a `rows` field on the way through the controller. Old
        // rows that already carry the field render harmlessly (the
        // dialog's raw-JSON dump shows it; the table cell ignores it).
        $job = $this->makeJob([
            'rows_received' => 50,
            'rows_inserted' => 48,
            'total_duplicates' => 2,
        ]);

        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertInertia(function (AssertableInertia $page) use ($job) {
                $page
                    ->where('jobs.data.0.id', $job->id)
                    ->where('jobs.data.0.stats.rows_received', 50)
                    ->where('jobs.data.0.stats.rows_inserted', 48)
                    ->missing('jobs.data.0.stats.rows');
            });
    }

    public function test_index_preserves_legacy_rows_field_on_old_completed_rows(): void
    {
        // We deliberately don't backfill-delete `rows` on jobs that
        // already have it persisted (the field is harmless once the UI
        // stops surfacing it). Verify it makes it through the
        // controller's `through()` projection so the dialog's raw
        // `<pre>` JSON dump renders the historical record verbatim.
        $job = $this->makeJob([
            'rows' => 150,
            'rows_received' => 150,
            'rows_inserted' => 150,
            'total_duplicates' => 0,
            'pages' => 6,
            'stop_reason' => 'caught_up',
        ]);

        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertInertia(function (AssertableInertia $page) use ($job) {
                $page
                    ->where('jobs.data.0.id', $job->id)
                    ->where('jobs.data.0.stats.rows', 150)
                    ->where('jobs.data.0.stats.rows_received', 150)
                    ->where('jobs.data.0.stats.rows_inserted', 150)
                    ->where('jobs.data.0.stats.stop_reason', 'caught_up');
            });
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function makeJob(array $stats, string $status = ScrapeJob::STATUS_COMPLETED): ScrapeJob
    {
        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'Row Counts Test Org',
        ]);

        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Row Counts Test App',
        ]);

        $sub = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Row Counts Test Sub',
            'environment' => 'production',
        ]);

        $session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);

        return ScrapeJob::create([
            'subscription_id' => $sub->id,
            'bex_session_id' => $session->id,
            'status' => $status,
            'attempts' => 1,
            'started_at' => now()->subMinute(),
            'completed_at' => $status === ScrapeJob::STATUS_COMPLETED ? now() : null,
            'stats' => $stats,
        ]);
    }
}
