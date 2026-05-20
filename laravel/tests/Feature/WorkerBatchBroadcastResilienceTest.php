<?php

namespace Tests\Feature;

use App\Events\LogBatchInserted;
use App\Events\ScrapeJobUpdated;
use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the broadcast-failure-must-not-fail-mutation
 * contract introduced in `App\Support\SafeBroadcast`. Job 38616
 * ingested 15820 rows successfully and was then marked failed because
 * a synchronous Reverb broadcast inside `WorkerController::batch`
 * timed out at 10s (`cURL error 28` against the public hostname); the
 * /batch endpoint returned 500, the worker's catch path called /fail,
 * /fail's broadcast also timed out, the job died.
 *
 * The fix has two layers:
 *
 *   1. Internal docker-network routing for server-side broadcasts
 *      (docker-compose.production.yml's `app.environment.REVERB_*`
 *      overrides). Removes the WAN round-trip — the actual cause.
 *
 *   2. `SafeBroadcast::dispatch` wrappers around every `broadcast()`
 *      call in WorkerController + JobsController. Defense-in-depth
 *      so any future routing regression / Reverb wobble can't take
 *      a worker job down again.
 *
 * This test pins layer (2): with a broadcaster that throws on every
 * call, the /batch endpoint must still return 200, persist the rows,
 * and update the job's stats. A warning must be logged so the
 * operator can correlate.
 */
class WorkerBatchBroadcastResilienceTest extends TestCase
{
    use DatabaseTransactions;

    private ScrapeJob $job;

    private string $subId;

    protected function setUp(): void
    {
        $driver = (string) (getenv('DB_CONNECTION') ?: env('DB_CONNECTION', 'sqlite'));
        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                'Worker batch broadcast tests share the bytea content_hash bind path with WorkerBatchStatsTest; '
                .'rerun with DB_CONNECTION=pgsql.',
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
            'name' => 'Worker Broadcast Org',
        ]);

        Application::create([
            'id' => $appId,
            'organization_id' => $orgId,
            'name' => 'Worker Broadcast App',
        ]);

        Subscription::create([
            'id' => $this->subId,
            'application_id' => $appId,
            'name' => 'Worker Broadcast Sub',
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

        $this->bindThrowingBroadcaster();
    }

    /**
     * Replace the `BroadcastFactory` binding with a stub whose
     * methods throw the same shape of error the production Pusher
     * driver emits when Reverb is unreachable. Targets the
     * `Factory` contract specifically because that's what the
     * global `broadcast()` helper resolves through —
     * `app(BroadcastFactory::class)->event($event)` on
     * `vendor/laravel/framework/src/Illuminate/Foundation/helpers.php`.
     * `event()` is not declared on the contract (only `connection()`
     * is), but Laravel's `BroadcastManager` adds it; the anonymous
     * stub here adds it with throwing semantics so the helper's
     * call from `SafeBroadcast::dispatch` lands on a throwing path.
     */
    private function bindThrowingBroadcaster(): void
    {
        $this->app->instance(BroadcastFactory::class, new class implements BroadcastFactory
        {
            private const REVERB_DOWN = 'simulated reverb outage: cURL error 28: Connection timed out after 10002 milliseconds';

            public function connection($name = null)
            {
                throw new \RuntimeException(self::REVERB_DOWN);
            }

            public function event($event = null)
            {
                throw new \RuntimeException(self::REVERB_DOWN);
            }

            public function queue($event)
            {
                throw new \RuntimeException(self::REVERB_DOWN);
            }
        });
    }

    public function test_batch_succeeds_and_persists_rows_when_broadcaster_throws(): void
    {
        // Spy on the Log facade so we can assert the warning fired.
        // Log::spy() returns a Mockery spy that records every call
        // and lets us run `shouldHaveReceived` after the fact —
        // unlike `Log::fake()` (which doesn't exist), this records
        // real calls without skipping the underlying handler.
        Log::spy();

        $messages = [
            $this->message('2026-05-19T10:00:00Z', 1),
            $this->message('2026-05-19T10:00:01Z', 2),
        ];

        $response = $this
            ->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$this->job->id}/batch", [
                'messages' => $messages,
            ]);

        // Contract #1: the /batch endpoint MUST NOT propagate the
        // broadcast failure as a 500. It must respond 200 with the
        // correct row counts because the data write succeeded.
        $response->assertOk();
        $response->assertExactJson([
            'received' => 2,
            'inserted' => 2,
        ]);

        // Contract #2: the rows must actually be in the DB. This is
        // the authoritative side of the contract — broadcast is
        // best-effort; ingestion must be unaffected.
        $this->assertSame(2, $this->countLogRowsForJob());

        // Contract #3: the job's stats counters must reflect the
        // batch (so the Jobs UI doesn't go stale). All the stats
        // accumulation in WorkerController::batch happens INSIDE
        // the transaction, BEFORE the broadcasts — but a regression
        // could move it after the broadcast wrapper, and this
        // assertion would catch it.
        $this->job->refresh();
        $this->assertSame(2, (int) ($this->job->stats['rows_received'] ?? -1));
        $this->assertSame(2, (int) ($this->job->stats['rows_inserted'] ?? -1));
        $this->assertSame(1, (int) ($this->job->stats['batches'] ?? -1));

        // Contract #4: warnings were logged so an operator can
        // correlate "broadcasts mysteriously failing" with a
        // specific call site. Both broadcasts in batch() — the
        // LogBatchInserted (since $stored > 0) AND the trailing
        // ScrapeJobUpdated — produce their own context-tagged
        // warning.
        Log::shouldHaveReceived('warning')
            ->with('Broadcast failed in WorkerController::batch.LogBatchInserted', \Mockery::on(
                fn ($context) => is_array($context)
                    && ($context['event'] ?? null) === LogBatchInserted::class
                    && str_contains((string) ($context['exception'] ?? ''), 'cURL error 28'),
            ))
            ->once();
        Log::shouldHaveReceived('warning')
            ->with('Broadcast failed in WorkerController::batch.ScrapeJobUpdated', \Mockery::on(
                fn ($context) => is_array($context)
                    && ($context['event'] ?? null) === ScrapeJobUpdated::class
                    && str_contains((string) ($context['exception'] ?? ''), 'cURL error 28'),
            ))
            ->once();
    }

    private function countLogRowsForJob(): int
    {
        return (int) DB::table('log_messages')
            ->join('pages', 'pages.id', '=', 'log_messages.page_id')
            ->where('pages.subscription_id', $this->subId)
            ->count();
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
