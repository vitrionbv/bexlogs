<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Regression coverage for the dedup counter bug. The previous Eloquent
 * `LogMessage::query()->upsert($rows, [page_id, content_hash], [])` call
 * silently appended `updated_at` to the update list (Eloquent's
 * `addUpdatedAtToUpsertColumns()` runs whenever the model uses
 * timestamps), which compiled to `ON CONFLICT (...) DO UPDATE SET
 * updated_at = excluded.updated_at` on Postgres. Postgres' `affected_rows
 * = inserts + updates` then meant `rows_inserted` always equaled
 * `rows_received` and `total_duplicates` was always 0, breaking the
 * scraper's `pageReceived > 0 && pageInserted === 0` early-stop trigger
 * (subscriptions like EuroParcs walked the full 1000-page cap on every
 * run instead of stopping when they caught up to already-scraped
 * territory).
 *
 * The fix replaces the call with `DB::table('log_messages')
 * ->insertOrIgnore($rows)`, which compiles to `ON CONFLICT DO NOTHING`
 * on Postgres (relying on the `log_messages_page_content_hash_idx`
 * unique index) and `INSERT OR IGNORE INTO ...` on SQLite. PDO's
 * `rowCount()` returns the genuinely-new insert count on both drivers,
 * which is the semantic the early-stop logic and the
 * `total_duplicates` counter need.
 *
 * Gated on pgsql for parity with `WorkerBatchStatsTest` — the bytea
 * `content_hash` bind path and the TIMESTAMP precision behavior we
 * assert on (`updated_at` byte-identical across re-inserts) are both
 * pgsql-specific, and pgsql is the production driver.
 */
class WorkerBatchDedupTest extends TestCase
{
    use DatabaseTransactions;

    private ScrapeJob $job;

    private string $subId;

    protected function setUp(): void
    {
        $driver = (string) (getenv('DB_CONNECTION') ?: env('DB_CONNECTION', 'sqlite'));
        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                'Worker batch dedup test exercises the bytea content_hash bind '
                .'and pgsql ON CONFLICT DO NOTHING semantics; '
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
            'name' => 'Worker Dedup Org',
        ]);

        Application::create([
            'id' => $appId,
            'organization_id' => $orgId,
            'name' => 'Worker Dedup App',
        ]);

        Subscription::create([
            'id' => $this->subId,
            'application_id' => $appId,
            'name' => 'Worker Dedup Sub',
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

    public function test_fresh_batch_inserts_all_rows_and_records_zero_duplicates(): void
    {
        $messages = $this->messages(8, base: 'fresh');

        $response = $this->postBatch($messages)->assertOk();

        $response->assertExactJson([
            'received' => 8,
            'inserted' => 8,
        ]);

        $this->job->refresh();

        $this->assertSame(8, (int) ($this->job->stats['rows_received'] ?? -1));
        $this->assertSame(8, (int) ($this->job->stats['rows_inserted'] ?? -1));
        $this->assertSame(0, (int) ($this->job->stats['total_duplicates'] ?? -1));
        $this->assertSame(8, $this->countLogRowsForJob());
    }

    public function test_reinserting_same_rows_returns_zero_inserted_and_n_duplicates(): void
    {
        $messages = $this->messages(6, base: 'reinsert');

        $this->postBatch($messages)->assertOk();
        $this->job->refresh();
        $statsAfterFirst = $this->job->stats ?? [];

        // Snapshot the originals' updated_at values so we can prove
        // ON CONFLICT DO NOTHING actually leaves them untouched (the
        // pre-fix Eloquent upsert silently bumped them via
        // addUpdatedAtToUpsertColumns). We compare raw string casts so
        // any sub-microsecond drift surfaces.
        $beforeUpdatedAt = $this->snapshotUpdatedAt();

        $response = $this->postBatch($messages)->assertOk();

        $response->assertExactJson([
            'received' => 6,
            'inserted' => 0,
        ]);

        $this->job->refresh();

        $this->assertSame(
            12,
            (int) ($this->job->stats['rows_received'] ?? -1),
            'rows_received accumulates across batches (pre-dedup count)',
        );
        $this->assertSame(
            6,
            (int) ($this->job->stats['rows_inserted'] ?? -1),
            'rows_inserted only counts the genuine inserts from the first batch',
        );
        $this->assertSame(
            6,
            (int) ($this->job->stats['total_duplicates'] ?? -1),
            'total_duplicates accumulates the rows the unique index rejected on the second batch',
        );
        $this->assertSame(
            (int) ($statsAfterFirst['batches'] ?? 0) + 1,
            (int) ($this->job->stats['batches'] ?? 0),
            'batches counter increments on every /batch POST regardless of insert outcome',
        );
        $this->assertSame(6, $this->countLogRowsForJob(), 'no duplicate rows materialized');

        $afterUpdatedAt = $this->snapshotUpdatedAt();
        $this->assertSame(
            $beforeUpdatedAt,
            $afterUpdatedAt,
            'updated_at must be byte-identical after a duplicate-only batch — '
                .'proves ON CONFLICT DO NOTHING (not DO UPDATE SET updated_at = excluded.updated_at)',
        );
    }

    public function test_mixed_batch_inserts_only_new_rows_and_counts_overlap_as_duplicates(): void
    {
        $primer = $this->messages(5, base: 'overlap');
        $this->postBatch($primer)->assertOk();
        $this->job->refresh();
        $statsAfterPrimer = $this->job->stats ?? [];

        // 5 of the 10 messages collide with primer (same base) → DO NOTHING;
        // the other 5 are genuinely new (different base) → inserted.
        $mixed = array_merge(
            $this->messages(5, base: 'overlap'),
            $this->messages(5, base: 'fresh-after-overlap'),
        );

        $response = $this->postBatch($mixed)->assertOk();

        $response->assertExactJson([
            'received' => 10,
            'inserted' => 5,
        ]);

        $this->job->refresh();

        $this->assertSame(
            (int) ($statsAfterPrimer['rows_received'] ?? 0) + 10,
            (int) ($this->job->stats['rows_received'] ?? 0),
        );
        $this->assertSame(
            (int) ($statsAfterPrimer['rows_inserted'] ?? 0) + 5,
            (int) ($this->job->stats['rows_inserted'] ?? 0),
        );
        $this->assertSame(
            5,
            (int) ($this->job->stats['total_duplicates'] ?? -1),
            'mixed batch contributes exactly the overlap count to total_duplicates',
        );
        $this->assertSame(10, $this->countLogRowsForJob());
    }

    public function test_total_duplicates_accumulates_additively_across_multiple_duplicate_batches(): void
    {
        $primer = $this->messages(3, base: 'additive');
        $this->postBatch($primer)->assertOk();

        $this->postBatch($primer)->assertOk();
        $this->postBatch($primer)->assertOk();

        $this->job->refresh();

        $this->assertSame(9, (int) ($this->job->stats['rows_received'] ?? -1));
        $this->assertSame(3, (int) ($this->job->stats['rows_inserted'] ?? -1));
        $this->assertSame(
            6,
            (int) ($this->job->stats['total_duplicates'] ?? -1),
            'two duplicate batches of 3 → total_duplicates = 6 (additive)',
        );
    }

    /**
     * @return array<int, string>
     */
    private function snapshotUpdatedAt(): array
    {
        // Cast the timestamp via SQL so we observe what Postgres has on
        // disk, not Carbon's PHP-side reformatting. Sub-microsecond
        // drift between writes shows up in the formatted string.
        // Pluck via an alias because passing a raw to_char(...) call
        // straight to ->pluck() trips the per-row stdClass property
        // lookup on the format string's punctuation.
        $rows = DB::table('log_messages')
            ->join('pages', 'pages.id', '=', 'log_messages.page_id')
            ->where('pages.subscription_id', $this->subId)
            ->orderBy('log_messages.id')
            ->selectRaw("to_char(log_messages.updated_at, 'YYYY-MM-DD HH24:MI:SS.US') AS updated_at_str")
            ->get();

        return $rows->pluck('updated_at_str')->all();
    }

    private function countLogRowsForJob(): int
    {
        return (int) DB::table('log_messages')
            ->join('pages', 'pages.id', '=', 'log_messages.page_id')
            ->where('pages.subscription_id', $this->subId)
            ->count();
    }

    /**
     * Generate N deterministic messages keyed off `$base` so two calls
     * with the same `$base` produce byte-identical messages (and
     * therefore byte-identical content_hashes once
     * LogMessageHasher::compute runs server-side). Different `$base`
     * values produce different timestamps + parameters so the hashes
     * diverge.
     *
     * @return array<int, array<string, mixed>>
     */
    private function messages(int $n, string $base): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'timestamp' => sprintf('2026-05-01T%02d:%02d:00Z', 8, $i),
                'type' => 'Api Call',
                'action' => "{$base}-action-{$i}",
                'method' => 'GET',
                'status' => '200',
                'parameters' => ['endpoint' => "/v1/{$base}", 'page' => $i],
                'request' => ['headers' => ['accept' => 'application/json']],
                'response' => ['ok' => true, 'index' => $i],
            ];
        }

        return $out;
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
}
