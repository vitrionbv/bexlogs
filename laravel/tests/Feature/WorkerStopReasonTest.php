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
        // duplicate_detection is one of the two canonical "healthy
        // completion" reasons — the legacy "we caught up to already-
        // scraped territory" signal. Picking it here exercises the path
        // the Jobs UI cares most about (the green "Caught up" badge).
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 5,
                'duration_ms' => 1500,
                'aborted_due_to_time' => false,
                'early_stopped_due_to_duplicates' => true,
                'total_duplicates' => 12,
                'stop_reason' => 'duplicate_detection',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertSame('duplicate_detection', $this->job->stats['stop_reason'] ?? null);
        $this->assertSame(5, (int) ($this->job->stats['pages'] ?? 0));
        $this->assertArrayNotHasKey(
            'rows',
            $this->job->stats,
            'legacy stats.rows must not be persisted (post-fix the Jobs UI reads rows_received/rows_inserted instead)',
        );
    }

    public function test_complete_accepts_caught_up_stop_reason_and_token_echo_retries_counter(): void
    {
        // caught_up is the second canonical healthy completion: the
        // scraper's `loadMoreWithTokenEchoRetry` exhausted its 3
        // attempts at next_token == sentToken (BookingExperts'
        // AWS-CloudWatch-Logs-style "you're at the live tip" signal).
        // The diagnostic counter `token_echo_retries` rides along on
        // /complete so the operator can see how many wasted echo
        // attempts the helper absorbed across the whole scrape.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 12,
                'duration_ms' => 90_000,
                'aborted_due_to_time' => false,
                'token_echo_retries' => 2,
                'stop_reason' => 'caught_up',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertSame('caught_up', $this->job->stats['stop_reason'] ?? null);
        $this->assertSame(2, (int) ($this->job->stats['token_echo_retries'] ?? -1));
    }

    public function test_complete_accepts_empty_window_with_initial_page_retries_counter(): void
    {
        // Post-retry-layer `empty_window` completion: the scraper's
        // `loadInitialPageWithRetry` exhausted `TOKEN_ECHO_MAX_ATTEMPTS`
        // attempts (default 100) of `rows.length === 0 && nextToken === null`
        // and gave up. The diagnostic counter `initial_page_retries`
        // rides along on /complete so the operator can tell a 1-attempt
        // fast-exit apart from a full 100-attempt grind by reading the
        // Stats dialog (the `empty_window` tooltip also surfaces it).
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 0,
                'duration_ms' => 297_500,
                'aborted_due_to_time' => false,
                'initial_page_retries' => 99,
                'stop_reason' => 'empty_window',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertSame('empty_window', $this->job->stats['stop_reason'] ?? null);
        $this->assertSame(99, (int) ($this->job->stats['initial_page_retries'] ?? -1));
    }

    public function test_complete_accepts_zero_initial_page_retries_fast_path(): void
    {
        // Common path: the first attempt already surfaced real data,
        // so `loadInitialPageWithRetry` exits on attempt 1 and the
        // worker sends `initial_page_retries: 0`. Validator must
        // accept 0 (not treat `>= 1` as required) so the counter is
        // populated on every completion regardless of outcome.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 8,
                'duration_ms' => 12_000,
                'initial_page_retries' => 0,
                'stop_reason' => 'caught_up',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertSame(0, (int) ($this->job->stats['initial_page_retries'] ?? -1));
    }

    public function test_complete_rejects_negative_initial_page_retries(): void
    {
        // Defensive: the counter is `max(0, attempts - 1)` in the
        // scraper, so the only way a negative value reaches Laravel
        // is a crafted request or a future refactor bug. The `min:0`
        // validator catches it before it lands in `stats`.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 1,
                'duration_ms' => 100,
                'initial_page_retries' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['initial_page_retries']);

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_RUNNING, $this->job->status);
    }

    public function test_complete_rejects_retired_token_echo_reason(): void
    {
        // `token_echo` was retired in favor of `caught_up`. The scraper
        // no longer emits this value (echoes go through the retry
        // helper and resolve to `caught_up` or `advanced`); the
        // validator must reject it so a stale older worker can't
        // re-introduce a destructive badge for what is actually a
        // healthy completion.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 1,
                'duration_ms' => 100,
                'stop_reason' => 'token_echo',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stop_reason']);

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_RUNNING, $this->job->status);
    }

    public function test_complete_rejects_retired_natural_end_reason(): void
    {
        // `natural_end` was retired in favor of `duplicate_detection`
        // (caught up) and `token_missing` / `pagination_error` (the
        // anomaly paths it used to silently absorb). The validator
        // must reject the legacy key so a stray older worker can't
        // re-introduce it through /complete.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 1,
                'duration_ms' => 100,
                'stop_reason' => 'natural_end',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stop_reason']);

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_RUNNING, $this->job->status);
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
                'duration_ms' => 200,
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertArrayNotHasKey('stop_reason', $this->job->stats ?? []);
    }

    public function test_complete_drops_legacy_rows_field_at_merge_for_rolling_deploy_workers(): void
    {
        // Rolling-deploy guard: an older scraper image still in flight
        // when the new server-side merge lands may keep sending the
        // legacy `rows` field. We accept it through the validator (so
        // the request doesn't 422 and the worker doesn't crash) but
        // strip it before persisting so it can't re-pollute the Jobs
        // UI's row counters. New workers in the same deploy cycle no
        // longer send it.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 6,
                'rows' => 150,
                'duration_ms' => 8_000,
                'stop_reason' => 'caught_up',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $stats = $this->job->stats ?? [];

        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertSame('caught_up', $stats['stop_reason'] ?? null);
        $this->assertSame(6, (int) ($stats['pages'] ?? 0));
        $this->assertArrayNotHasKey(
            'rows',
            $stats,
            'legacy stats.rows must not be persisted post-fix even if a rolling-deploy worker sends it',
        );
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

    public function test_fail_with_explicit_pagination_error_persists_typed_reason(): void
    {
        // 422-after-retries path: scraper sets stop_reason = pagination_error
        // before throwing, then forwards it through failJob({ stop_reason }).
        // The Jobs UI then renders the destructive "Pagination error (422)"
        // badge so the operator notices we hit BE too hard.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/fail", [
                'error' => 'BookingExperts returned 422 after 3 attempts (1 initial + 2 retries). Consider lowering MAX_CONCURRENT_SCRAPES.',
                'retryable' => true,
                'stop_reason' => 'pagination_error',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $this->job->status);
        $this->assertSame('pagination_error', $this->job->stats['stop_reason'] ?? null);
        $this->assertStringContainsString('MAX_CONCURRENT_SCRAPES', (string) $this->job->error);
    }

    public function test_fail_with_explicit_token_missing_persists_typed_reason(): void
    {
        // Mid-scrape next_token === null path: scraper sets
        // stop_reason = token_missing before throwing. The Jobs UI then
        // renders the destructive "Missing pagination token" badge.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/fail", [
                'error' => 'BookingExperts stopped returning a next_token mid-scrape; pagination state lost — treating as anomaly.',
                'retryable' => true,
                'stop_reason' => 'token_missing',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $this->job->status);
        $this->assertSame('token_missing', $this->job->stats['stop_reason'] ?? null);
    }

    public function test_fail_explicit_stop_reason_wins_over_session_expired_sentinel_detection(): void
    {
        // Edge case: the worker forwards an explicit stop_reason AND
        // happens to use the SESSION_EXPIRED string as the error message
        // (e.g. a future code path that wants to label session expiry
        // differently than the legacy sentinel). Explicit > sentinel
        // detection — the SESSION_EXPIRED fallback only kicks in when no
        // stop_reason is supplied at all.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/fail", [
                'error' => 'SESSION_EXPIRED',
                'retryable' => false,
                'stop_reason' => 'pagination_error',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_FAILED, $this->job->status);
        $this->assertSame('pagination_error', $this->job->stats['stop_reason'] ?? null);
    }

    public function test_fail_rejects_retired_natural_end_reason(): void
    {
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/fail", [
                'error' => 'load_more HTTP 500',
                'stop_reason' => 'natural_end',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stop_reason']);

        $this->job->refresh();
        $this->assertSame(ScrapeJob::STATUS_RUNNING, $this->job->status);
    }
}
