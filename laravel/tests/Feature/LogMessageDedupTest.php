<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\LogMessage;
use App\Models\Organization;
use App\Models\Page;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Verifies the new content-hash dedup semantics on `log_messages`:
 *  - identical payloads dedupe to one row (pagination overlap protection),
 *  - different payloads with the same (timestamp, type, action, method,
 *    status) tuple are kept as separate rows (legitimate same-second
 *    duplicates must remain visible).
 *
 * The original `create_log_messages_table` migration uses Postgres-only
 * `CREATE INDEX … USING GIN`, so we cannot use the default in-memory
 * SQLite test database. Instead we rely on the dev Postgres DB and
 * `DatabaseTransactions` to roll back every test cleanly. If the test
 * suite is invoked with the default SQLite override (phpunit.xml sets
 * `DB_CONNECTION=sqlite`) the test is skipped with a TODO.
 */
class LogMessageDedupTest extends TestCase
{
    use DatabaseTransactions;

    private ScrapeJob $job;

    private string $orgId;

    private string $appId;

    private string $subId;

    protected function setUp(): void
    {
        // Check BEFORE bootstrapping Laravel — phpunit.xml forces
        // DB_CONNECTION=sqlite which can't run the GIN index migration.
        $driver = (string) (getenv('DB_CONNECTION') ?: env('DB_CONNECTION', 'sqlite'));
        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                'log_messages uses Postgres-only GIN indexes; '
                .'rerun with DB_CONNECTION=pgsql to exercise dedup.',
            );
        }

        parent::setUp();

        config(['bex.worker_api_token' => 'test-worker-token']);

        $this->orgId = 'tst-org-'.Str::random(8);
        $this->appId = 'tst-app-'.Str::random(8);
        $this->subId = 'tst-sub-'.Str::random(8);

        $user = User::factory()->create();

        Organization::create([
            'id' => $this->orgId,
            'user_id' => $user->id,
            'name' => 'Dedup Test Org',
        ]);

        Application::create([
            'id' => $this->appId,
            'organization_id' => $this->orgId,
            'name' => 'Dedup Test App',
        ]);

        Subscription::create([
            'id' => $this->subId,
            'application_id' => $this->appId,
            'name' => 'Dedup Test Sub',
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
            'status' => ScrapeJob::STATUS_QUEUED,
        ]);
    }

    public function test_identical_payloads_dedupe_to_one_row(): void
    {
        $msg = $this->message();

        $this->postBatch([$msg, $msg])->assertOk();

        $this->assertSame(1, $this->countLogs());
    }

    public function test_distinct_payloads_at_same_timestamp_remain_separate(): void
    {
        $base = $this->message();

        $second = $base;
        $second['parameters'] = ['endpoint' => '/v1/things', 'page' => 2];

        $this->postBatch([$base, $second])->assertOk();

        $this->assertSame(
            2,
            $this->countLogs(),
            'Two distinct payloads sharing (timestamp, type, action, method, status) must keep two rows.',
        );
    }

    public function test_re_posting_an_already_stored_message_is_a_no_op(): void
    {
        $msg = $this->message();

        $this->postBatch([$msg])->assertOk();
        $this->postBatch([$msg])->assertOk();

        $this->assertSame(1, $this->countLogs());
    }

    /**
     * Two concurrent scrape jobs for the same subscription must share a
     * Page row (the `pages_unique_idx` on (org_id, app_id, subscription_id)
     * forces it) and therefore the `(page_id, content_hash)` unique index
     * dedupes their writes naturally — even though the jobs themselves
     * have separate ids. This is the property the per-subscription
     * concurrency feature relies on for `duplicate_detection` early-stop.
     */
    public function test_two_concurrent_jobs_for_same_subscription_dedup_via_shared_page(): void
    {
        $secondJob = ScrapeJob::create([
            'subscription_id' => $this->subId,
            'bex_session_id' => $this->job->bex_session_id,
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        $msg = $this->message();

        $this->postBatch([$msg])->assertOk();
        $this->postBatchAs($secondJob, [$msg])->assertOk();

        $this->assertSame(
            1,
            $this->countLogs(),
            'Two jobs writing the same payload for the same subscription must dedup to one row.',
        );

        $pageIds = Page::query()
            ->where('subscription_id', $this->subId)
            ->pluck('id')
            ->all();

        $this->assertCount(
            1,
            $pageIds,
            'A subscription must have exactly one Page row regardless of how many jobs touch it.',
        );
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function postBatch(array $messages): TestResponse
    {
        return $this->postBatchAs($this->job, $messages);
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function postBatchAs(ScrapeJob $job, array $messages): TestResponse
    {
        return $this
            ->withToken('test-worker-token')
            ->postJson("/api/worker/jobs/{$job->id}/batch", ['messages' => $messages]);
    }

    private function countLogs(): int
    {
        return LogMessage::query()
            ->whereIn('page_id', Page::query()
                ->where('subscription_id', $this->subId)
                ->select('id'))
            ->count();
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return [
            'timestamp' => '2026-04-30T12:00:00Z',
            'type' => 'Api Call',
            'action' => 'list-things',
            'method' => 'GET',
            'status' => '200',
            'parameters' => ['endpoint' => '/v1/things', 'page' => 1],
            'request' => ['headers' => ['accept' => 'application/json']],
            'response' => ['ok' => true],
        ];
    }
}
