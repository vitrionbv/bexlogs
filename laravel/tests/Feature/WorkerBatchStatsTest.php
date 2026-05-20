<?php

namespace Tests\Feature;

use App\Events\ScrapeJobUpdated;
use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Batch POSTs upsert log rows immediately and accumulate scrape_jobs.stats
 * for live progress; each batch dispatches ScrapeJobUpdated with merged stats.
 */
class WorkerBatchStatsTest extends TestCase
{
    use DatabaseTransactions;

    private ScrapeJob $job;

    private string $subId;

    protected function setUp(): void
    {
        $driver = (string) (getenv('DB_CONNECTION') ?: env('DB_CONNECTION', 'sqlite'));
        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                'Worker batch tests use the same stack as log_messages; rerun with DB_CONNECTION=pgsql.',
            );
        }

        parent::setUp();

        config(['bex.worker_api_token' => 'test-worker-token']);

        $orgId = 'tst-org-'.Str::random(8);
        $appId = 'tst-app-'.Str::random(8);
        $this->subId = 'tst-sub-'.Str::random(8);

        $user = User::factory()->create();

        Organization::create([
            'id' => $orgId,
            'user_id' => $user->id,
            'name' => 'Worker Batch Org',
        ]);

        Application::create([
            'id' => $appId,
            'organization_id' => $orgId,
            'name' => 'Worker Batch App',
        ]);

        Subscription::create([
            'id' => $this->subId,
            'application_id' => $appId,
            'name' => 'Worker Batch Sub',
            'environment' => 'production',
        ]);

        $session = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);

        $this->job = ScrapeJob::create([
            'subscription_id' => $this->subId,
            'bex_session_id' => $session->id,
            'status' => ScrapeJob::STATUS_RUNNING,
        ]);
    }

    public function test_batch_accumulates_stats_and_dispatches_event_per_request(): void
    {
        Event::fake([ScrapeJobUpdated::class]);

        $a = $this->message('2026-05-01T10:00:00Z', 1);
        $b = $this->message('2026-05-01T10:00:01Z', 2);

        $this->postBatch([$a])->assertOk();
        $this->postBatch([$b], pagesProcessed: 2)->assertOk();

        $this->job->refresh();

        $this->assertSame(2, (int) ($this->job->stats['rows_received'] ?? 0));
        $this->assertSame(2, (int) ($this->job->stats['rows_inserted'] ?? 0));
        $this->assertSame(2, (int) ($this->job->stats['batches'] ?? 0));
        $this->assertSame(2, (int) ($this->job->stats['pages_processed'] ?? 0));

        Event::assertDispatched(ScrapeJobUpdated::class, 2);
        Event::assertDispatched(
            ScrapeJobUpdated::class,
            fn (ScrapeJobUpdated $e) => $e->jobId === $this->job->id
                && $e->stats !== null
                && (int) ($e->stats['rows_inserted'] ?? 0) === 1
        );
        Event::assertDispatched(
            ScrapeJobUpdated::class,
            fn (ScrapeJobUpdated $e) => $e->jobId === $this->job->id
                && $e->stats !== null
                && (int) ($e->stats['rows_inserted'] ?? 0) === 2
        );
    }

    public function test_complete_merges_final_stats_without_dropping_batch_counters(): void
    {
        $this->postBatch([$this->message('2026-05-01T11:00:00Z', 1)])->assertOk();

        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 3,
                // `rows` is the legacy field — pre-fix the scraper sent
                // it as `pages_processed × BATCH_SIZE`, which gave the
                // Jobs UI suspiciously round counts instead of real
                // row numbers. The validator still ACCEPTS the field
                // (so a rolling-deploy worker still sending it doesn't
                // 422) but the merge drops it before persisting. The
                // contract is `rows_received` / `rows_inserted` from
                // the /batch endpoint.
                'rows' => 99,
                'duration_ms' => 1000,
            ])
            ->assertNoContent();

        $this->job->refresh();

        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertSame(3, (int) ($this->job->stats['pages'] ?? 0));
        $this->assertArrayNotHasKey(
            'rows',
            $this->job->stats,
            'legacy stats.rows must be stripped at the /complete merge so it cannot re-pollute the Jobs UI',
        );
        $this->assertSame(1, (int) ($this->job->stats['rows_inserted'] ?? 0));
        $this->assertSame(1, (int) ($this->job->stats['batches'] ?? 0));
    }

    public function test_batch_persists_per_page_echo_attempts_into_stats(): void
    {
        // The scraper re-sends the FULL list on every /batch (sender-
        // is-source-of-truth). Laravel overwrites rather than merges,
        // so a stale entry from an earlier batch can never linger
        // after the worker prunes one. This test locks in that
        // contract.
        $this->postBatch(
            messages: [$this->message('2026-05-01T13:00:00Z', 1)],
            echoAttempts: [
                ['page' => 0, 'attempts' => 3],   // initial-page helper fired 3 times
                ['page' => 90, 'attempts' => 7],  // load_more page 90 needed 7 attempts
            ],
        )->assertOk();

        $this->job->refresh();
        $persisted = $this->job->stats['echo_attempts_by_page'] ?? null;

        $this->assertSame(
            [
                ['page' => 0, 'attempts' => 3],
                ['page' => 90, 'attempts' => 7],
            ],
            $persisted,
            'batch endpoint must persist echo_attempts_by_page verbatim into scrape_jobs.stats',
        );

        // A second /batch with a different (shorter) list overwrites
        // the first — sender-of-truth, not append. Without the
        // overwrite-semantics, a pruned/recomputed entry from the
        // worker side would silently coexist with a stale value here.
        $this->postBatch(
            messages: [$this->message('2026-05-01T13:00:01Z', 2)],
            echoAttempts: [
                ['page' => 90, 'attempts' => 9],
            ],
        )->assertOk();

        $this->job->refresh();
        $this->assertSame(
            [['page' => 90, 'attempts' => 9]],
            $this->job->stats['echo_attempts_by_page'] ?? null,
        );
    }

    public function test_batch_rejects_invalid_echo_attempts_shape(): void
    {
        // Validator must reject malformed entries (negative page,
        // zero attempts, missing keys) so a buggy worker can't write
        // garbage into the JSON blob and break the UI's `.map()`.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/batch", [
                'messages' => [$this->message('2026-05-01T14:00:00Z', 1)],
                'echo_attempts_by_page' => [
                    ['page' => -1, 'attempts' => 5],
                ],
            ])
            ->assertStatus(422);

        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/batch", [
                'messages' => [$this->message('2026-05-01T14:00:00Z', 1)],
                'echo_attempts_by_page' => [
                    ['page' => 1, 'attempts' => 0],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_complete_persists_per_page_echo_attempts(): void
    {
        // The "last page exhausted with no row flush" case: when a
        // tail page hits the echo cap and produces zero rows, no
        // trailing /batch fires for that page. /complete is the
        // belt-and-suspenders that still gets the final retry count
        // into stats so the Jobs UI's per-page table renders the
        // exhausted-tail entry.
        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 5,
                'duration_ms' => 12000,
                'token_echo_retries' => 6,
                'echo_attempts_by_page' => [
                    ['page' => 5, 'attempts' => 100],
                ],
                'stop_reason' => 'caught_up',
            ])
            ->assertNoContent();

        $this->job->refresh();

        $this->assertSame(
            [['page' => 5, 'attempts' => 100]],
            $this->job->stats['echo_attempts_by_page'] ?? null,
        );
    }

    public function test_complete_persists_stop_reason_alongside_prior_batch_counters(): void
    {
        $this->postBatch([$this->message('2026-05-01T12:00:00Z', 1)])->assertOk();

        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 7,
                'duration_ms' => 2500,
                'aborted_due_to_time' => false,
                'early_stopped_due_to_duplicates' => true,
                'total_duplicates' => 12,
                'stop_reason' => 'duplicate_detection',
            ])
            ->assertNoContent();

        $this->job->refresh();
        $stats = $this->job->stats ?? [];

        $this->assertSame('duplicate_detection', $stats['stop_reason'] ?? null);
        $this->assertTrue((bool) ($stats['early_stopped_due_to_duplicates'] ?? false));
        $this->assertSame(12, (int) ($stats['total_duplicates'] ?? 0));
        // The per-batch counters from the prior /batch POST must survive
        // the /complete merge — see WorkerController::complete().
        $this->assertSame(1, (int) ($stats['rows_inserted'] ?? 0));
        $this->assertSame(1, (int) ($stats['batches'] ?? 0));
    }

    /**
     * Cross-batch rollup: per-batch min/max event timestamps shipped
     * via `batch_oldest_event_at` / `batch_newest_event_at` must
     * collapse into a job-lifetime `stats.oldest_event_at` /
     * `stats.newest_event_at` that always tracks the global extremes
     * across every batch the worker has shipped so far. This is the
     * "did the backfill actually reach the requested depth?" signal
     * the Jobs detail dialog surfaces alongside the requested window.
     */
    #[Test]
    public function it_rolls_up_oldest_and_newest_event_timestamps_across_batches(): void
    {
        // Batch #1 — the middle window. Establishes the baseline pair
        // (no prior values, so both fields are written verbatim).
        $b1Old = '2026-05-10T10:00:00Z';
        $b1New = '2026-05-10T18:00:00Z';
        $this->postBatch(
            messages: [$this->message('2026-05-10T12:00:00Z', 1)],
            oldestEventAt: $b1Old,
            newestEventAt: $b1New,
        )->assertOk();

        $this->job->refresh();
        $this->assertCarbonEq($b1Old, $this->job->stats['oldest_event_at'] ?? null);
        $this->assertCarbonEq($b1New, $this->job->stats['newest_event_at'] ?? null);

        // Batch #2 — older window. Should pull `oldest_event_at`
        // back; `newest_event_at` must NOT regress (b2 newest is
        // older than b1 newest).
        $b2Old = '2026-05-05T00:00:00Z';
        $b2New = '2026-05-05T23:59:59Z';
        $this->postBatch(
            messages: [$this->message('2026-05-05T12:00:00Z', 2)],
            oldestEventAt: $b2Old,
            newestEventAt: $b2New,
        )->assertOk();

        $this->job->refresh();
        $this->assertCarbonEq($b2Old, $this->job->stats['oldest_event_at'] ?? null);
        $this->assertCarbonEq(
            $b1New,
            $this->job->stats['newest_event_at'] ?? null,
            'newest_event_at must not regress when batch #2 is wholly inside the prior window',
        );

        // Batch #3 — newer window. Should push `newest_event_at`
        // forward; `oldest_event_at` must NOT regress (b3 oldest
        // is newer than b2 oldest, which currently holds the
        // global min).
        $b3Old = '2026-05-15T08:00:00Z';
        $b3New = '2026-05-20T20:00:00Z';
        $this->postBatch(
            messages: [$this->message('2026-05-18T12:00:00Z', 3)],
            oldestEventAt: $b3Old,
            newestEventAt: $b3New,
        )->assertOk();

        $this->job->refresh();
        $this->assertCarbonEq(
            $b2Old,
            $this->job->stats['oldest_event_at'] ?? null,
            'oldest_event_at must not regress when batch #3 starts inside the prior coverage',
        );
        $this->assertCarbonEq($b3New, $this->job->stats['newest_event_at'] ?? null);
    }

    /**
     * Edge case: a /batch POST without either rollup field must
     * leave the existing `stats.oldest_event_at` /
     * `stats.newest_event_at` untouched. The contract is "missing
     * = no signal" — never a null overwrite.
     */
    #[Test]
    public function it_preserves_existing_event_range_when_batch_omits_rollup_fields(): void
    {
        // Seed values from a non-empty batch.
        $seedOld = '2026-05-08T00:00:00Z';
        $seedNew = '2026-05-08T12:00:00Z';
        $this->postBatch(
            messages: [$this->message('2026-05-08T06:00:00Z', 1)],
            oldestEventAt: $seedOld,
            newestEventAt: $seedNew,
        )->assertOk();

        $this->job->refresh();
        $beforeOld = $this->job->stats['oldest_event_at'] ?? null;
        $beforeNew = $this->job->stats['newest_event_at'] ?? null;

        // Second batch carries messages but NO rollup fields (mirrors
        // an in-flight worker rolling deploy or a worker whose batch
        // had only unparseable timestamps — both legitimate "skip"
        // cases per the scraper-side contract in `postBatch`).
        $this->postBatch(
            messages: [$this->message('2026-05-08T07:00:00Z', 2)],
        )->assertOk();

        $this->job->refresh();
        $this->assertSame(
            $beforeOld,
            $this->job->stats['oldest_event_at'] ?? null,
            'oldest_event_at must survive a batch that omits batch_oldest_event_at',
        );
        $this->assertSame(
            $beforeNew,
            $this->job->stats['newest_event_at'] ?? null,
            'newest_event_at must survive a batch that omits batch_newest_event_at',
        );
    }

    /**
     * Edge case: a /batch POST that supplies only ONE side of the
     * pair must roll up just that side. Useful for the (rare)
     * single-message batch where min == max but the worker still
     * happens to send both (which is fine — exercised below
     * symmetrically), and for any future worker that decides to
     * skip one side because of a parse failure on a single
     * timestamp.
     */
    #[Test]
    public function it_rolls_up_only_the_supplied_side_when_one_field_is_missing(): void
    {
        // Seed the pair so we have prior values on both sides.
        $seedOld = '2026-05-12T00:00:00Z';
        $seedNew = '2026-05-12T12:00:00Z';
        $this->postBatch(
            messages: [$this->message('2026-05-12T06:00:00Z', 1)],
            oldestEventAt: $seedOld,
            newestEventAt: $seedNew,
        )->assertOk();

        $this->job->refresh();
        $beforeNew = $this->job->stats['newest_event_at'] ?? null;

        // Batch supplies only oldest — must pull oldest back, leave
        // newest exactly as it was.
        $newerOld = '2026-05-01T00:00:00Z';
        $this->postBatch(
            messages: [$this->message('2026-05-01T12:00:00Z', 2)],
            oldestEventAt: $newerOld,
        )->assertOk();

        $this->job->refresh();
        $this->assertCarbonEq($newerOld, $this->job->stats['oldest_event_at'] ?? null);
        $this->assertSame(
            $beforeNew,
            $this->job->stats['newest_event_at'] ?? null,
            'newest_event_at must survive a batch that supplies only batch_oldest_event_at',
        );

        // Symmetric: supply only newest — must push newest forward,
        // leave oldest exactly as it was.
        $beforeOld = $this->job->stats['oldest_event_at'] ?? null;
        $newerNew = '2026-05-25T23:00:00Z';
        $this->postBatch(
            messages: [$this->message('2026-05-25T22:00:00Z', 3)],
            newestEventAt: $newerNew,
        )->assertOk();

        $this->job->refresh();
        $this->assertSame(
            $beforeOld,
            $this->job->stats['oldest_event_at'] ?? null,
            'oldest_event_at must survive a batch that supplies only batch_newest_event_at',
        );
        $this->assertCarbonEq($newerNew, $this->job->stats['newest_event_at'] ?? null);
    }

    /**
     * Carbon-aware equality so we tolerate ISO 8601 reformatting
     * (the controller normalizes to `Carbon::toIso8601String()` —
     * `2026-05-10T10:00:00Z` flows in, `2026-05-10T10:00:00+00:00`
     * flows out). String compare would false-fail on the offset
     * shape; comparing as instants is the actual contract.
     */
    private function assertCarbonEq(string $expected, mixed $actual, string $message = ''): void
    {
        $this->assertNotNull($actual, $message ?: 'expected a non-null timestamp');
        $this->assertTrue(
            Carbon::parse($expected)->equalTo(Carbon::parse((string) $actual)),
            $message ?: "expected {$expected}, got {$actual}",
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array{page:int, attempts:int}>|null  $echoAttempts
     */
    private function postBatch(
        array $messages,
        ?int $pagesProcessed = null,
        ?array $echoAttempts = null,
        ?string $oldestEventAt = null,
        ?string $newestEventAt = null,
    ): TestResponse {
        $body = ['messages' => $messages];
        if ($pagesProcessed !== null) {
            $body['pages_processed'] = $pagesProcessed;
        }
        if ($echoAttempts !== null) {
            $body['echo_attempts_by_page'] = $echoAttempts;
        }
        if ($oldestEventAt !== null) {
            $body['batch_oldest_event_at'] = $oldestEventAt;
        }
        if ($newestEventAt !== null) {
            $body['batch_newest_event_at'] = $newestEventAt;
        }

        return $this
            ->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/batch", $body);
    }

    /** @return array<string, mixed> */
    private function message(string $ts, int $page): array
    {
        return [
            'timestamp' => $ts,
            'type' => 'Api Call',
            'action' => 'list-things',
            'method' => 'GET',
            'status' => '200',
            'parameters' => ['endpoint' => '/v1/things', 'page' => $page],
            'request' => ['headers' => ['accept' => 'application/json']],
            'response' => ['ok' => true],
        ];
    }
}
