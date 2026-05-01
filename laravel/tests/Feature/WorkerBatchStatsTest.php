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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
                'rows' => 99,
                'duration_ms' => 1000,
            ])
            ->assertNoContent();

        $this->job->refresh();

        $this->assertSame(ScrapeJob::STATUS_COMPLETED, $this->job->status);
        $this->assertSame(3, (int) ($this->job->stats['pages'] ?? 0));
        $this->assertSame(99, (int) ($this->job->stats['rows'] ?? 0));
        $this->assertSame(1, (int) ($this->job->stats['rows_inserted'] ?? 0));
        $this->assertSame(1, (int) ($this->job->stats['batches'] ?? 0));
    }

    public function test_complete_persists_stop_reason_alongside_prior_batch_counters(): void
    {
        $this->postBatch([$this->message('2026-05-01T12:00:00Z', 1)])->assertOk();

        $this->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/complete", [
                'pages' => 7,
                'rows' => 42,
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

    /** @param array<int, array<string, mixed>> $messages */
    private function postBatch(array $messages, ?int $pagesProcessed = null): TestResponse
    {
        $body = ['messages' => $messages];
        if ($pagesProcessed !== null) {
            $body['pages_processed'] = $pagesProcessed;
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
