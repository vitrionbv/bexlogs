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
use Tests\TestCase;

/**
 * Covers the `stats.stop_reason` plumbing on `WorkerController::complete()`
 * and `WorkerController::fail()`. Kept separate from `WorkerBatchStatsTest`
 * so it runs against the default sqlite suite — none of these cases need
 * the bytea-bound `log_messages` upsert path that gates that file on pgsql.
 */
class WorkerStopReasonTest extends TestCase
{
    use RefreshDatabase;

    private ScrapeJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bex.worker_api_token' => 'test-worker-token']);

        $user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $user->id,
            'name' => 'Stop Reason Org',
        ]);

        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Stop Reason App',
        ]);

        $sub = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Stop Reason Sub',
            'environment' => 'production',
        ]);

        $session = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);

        $this->job = ScrapeJob::create([
            'subscription_id' => $sub->id,
            'bex_session_id' => $session->id,
            'status' => ScrapeJob::STATUS_RUNNING,
            'attempts' => 1,
        ]);
    }

    public function test_complete_stamps_stop_reason_into_stats_blob(): void
    {
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 5,
                'rows' => 100,
                'duration_ms' => 1500,
                'aborted_due_to_time' => false,
                'early_stopped_due_to_duplicates' => false,
                'total_duplicates' => 0,
                'stop_reason' => 'natural_end',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertSame('natural_end', $this->job->stats['stop_reason'] ?? null);
        $this->assertSame(5, (int) ($this->job->stats['pages'] ?? 0));
    }

    public function test_complete_preserves_existing_per_batch_counters_on_stop_reason_merge(): void
    {
        // Simulate a prior /batch POST landing — set the per-batch keys
        // directly without exercising the pgsql-only upsert path.
        $this->job->forceFill([
            'stats' => [
                'rows_received' => 24,
                'rows_inserted' => 20,
                'batches' => 3,
                'pages_processed' => 2,
                'last_batch_at' => now()->toIso8601String(),
            ],
        ])->save();

        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 4,
                'rows' => 20,
                'duration_ms' => 800,
                'stop_reason' => 'pagination_limit',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $stats = $this->job->stats ?? [];

        $this->assertSame('pagination_limit', $stats['stop_reason'] ?? null);
        $this->assertSame(24, (int) ($stats['rows_received'] ?? 0));
        $this->assertSame(20, (int) ($stats['rows_inserted'] ?? 0));
        $this->assertSame(3, (int) ($stats['batches'] ?? 0));
        $this->assertSame(2, (int) ($stats['pages_processed'] ?? 0));
        $this->assertSame(4, (int) ($stats['pages'] ?? 0));
    }

    public function test_complete_rejects_unknown_stop_reason(): void
    {
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 1,
                'rows' => 0,
                'duration_ms' => 100,
                'stop_reason' => 'definitely-not-a-real-reason',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stop_reason']);

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_RUNNING, $this->job->status);
    }

    public function test_complete_accepts_omitted_stop_reason_for_backward_compat(): void
    {
        // Older worker builds (or one-shot replays) may not send the field.
        // The complete endpoint must still mark the job done without
        // injecting a stop_reason of its own.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 2,
                'rows' => 7,
                'duration_ms' => 200,
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertArrayNotHasKey('stop_reason', $this->job->stats ?? []);
    }

    public function test_fail_with_session_expired_sentinel_records_stop_reason(): void
    {
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/fail", [
                'error' => 'SESSION_EXPIRED',
                'retryable' => false,
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $this->job->status);
        $this->assertSame('session_expired', $this->job->stats['stop_reason'] ?? null);
        $this->assertSame('SESSION_EXPIRED', $this->job->error);
    }

    public function test_fail_with_session_expired_sentinel_preserves_prior_stats(): void
    {
        $this->job->forceFill([
            'stats' => [
                'rows_received' => 12,
                'rows_inserted' => 12,
                'batches' => 2,
            ],
        ])->save();

        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/fail", [
                'error' => 'SESSION_EXPIRED',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $stats = $this->job->stats ?? [];
        $this->assertSame('session_expired', $stats['stop_reason'] ?? null);
        $this->assertSame(12, (int) ($stats['rows_received'] ?? 0));
        $this->assertSame(2, (int) ($stats['batches'] ?? 0));
    }

    public function test_fail_with_generic_error_leaves_stop_reason_unset(): void
    {
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/fail", [
                'error' => 'load_more HTTP 500',
                'retryable' => true,
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $this->job->status);
        $stats = $this->job->stats ?? [];
        $this->assertArrayNotHasKey('stop_reason', $stats);
    }
}
