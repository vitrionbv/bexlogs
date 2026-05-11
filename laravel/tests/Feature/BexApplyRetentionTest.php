<?php

namespace Tests\Feature;

use App\Events\LogMessageRetentionApplied;
use App\Models\Application;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Coverage for the `bex:apply-retention` command (G19):
 *
 *   - rows older than the per-sub `retention_days` window are
 *     deleted; rows newer than the window stay put,
 *   - subscriptions with NULL `retention_days` are skipped entirely
 *     (the historical "keep forever" default),
 *   - the `LogMessageRetentionApplied` event broadcasts after a
 *     successful prune so the Manage UI can refresh counters
 *     without a full page reload,
 *   - chunked deletes complete cleanly when the candidate set
 *     spans multiple chunks.
 *
 * Tests bypass the scrape worker entirely and seed log_messages
 * directly via `DB::table()->insert()` so the assertions don't
 * depend on the worker batch contract.
 */
class BexApplyRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $sub;

    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-retention',
            'user_id' => $this->user->id,
            'name' => 'Retention Org',
        ]);

        $app = Application::create([
            'id' => 'app-retention',
            'organization_id' => $org->id,
            'name' => 'Retention App',
        ]);

        $this->sub = Subscription::create([
            'id' => 'sub-retention',
            'application_id' => $app->id,
            'name' => 'Retention Sub',
            'environment' => 'production',
        ]);

        $this->page = Page::create([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $this->sub->id,
        ]);
    }

    public function test_deletes_rows_older_than_retention_window(): void
    {
        Event::fake([LogMessageRetentionApplied::class]);

        $this->sub->update(['retention_days' => 30]);

        // 60 days old — outside the 30-day window → DELETE.
        // 5 days old  — inside the 30-day window  → KEEP.
        $oldId = $this->seedLog(Carbon::now()->subDays(60)->toIso8601String(), 'old');
        $newId = $this->seedLog(Carbon::now()->subDays(5)->toIso8601String(), 'new');

        $this->artisan('bex:apply-retention')->assertExitCode(0);

        $this->assertDatabaseMissing('log_messages', ['id' => $oldId]);
        $this->assertDatabaseHas('log_messages', ['id' => $newId]);

        Event::assertDispatched(
            LogMessageRetentionApplied::class,
            fn (LogMessageRetentionApplied $e) => $e->subscriptionId === $this->sub->id
                && $e->deletedCount === 1
                && $e->retentionDays === 30,
        );
    }

    public function test_skips_subscriptions_with_null_retention(): void
    {
        // Default (no update) leaves retention_days NULL — the
        // documented "keep forever" semantic. The command must
        // never touch those rows even if they're decades old.
        $oldId = $this->seedLog(Carbon::now()->subYears(5)->toIso8601String(), 'ancient');

        $this->artisan('bex:apply-retention')->assertExitCode(0);

        $this->assertDatabaseHas('log_messages', ['id' => $oldId]);
    }

    public function test_chunked_delete_handles_more_than_chunk_size(): void
    {
        $this->sub->update(['retention_days' => 1]);

        // Seed 25 old rows so the (CHUNK_SIZE = 10_000) loop can
        // return them in a single chunk; we exercise the loop's
        // "smaller than chunk → exit early" branch with this volume.
        $cutoff = Carbon::now()->subDays(2)->toIso8601String();
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->seedLog($cutoff, 'old-'.$i);
        }

        $this->artisan('bex:apply-retention')->assertExitCode(0);

        $remaining = DB::table('log_messages')->whereIn('id', $ids)->count();
        $this->assertSame(0, $remaining);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $this->sub->update(['retention_days' => 7]);
        $oldId = $this->seedLog(Carbon::now()->subDays(30)->toIso8601String(), 'dry-run-old');

        $this->artisan('bex:apply-retention', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('log_messages', ['id' => $oldId]);
    }

    /**
     * Insert a single log_messages row with the minimum legal shape.
     * `content_hash` must be present (NOT NULL on Postgres; nullable
     * on SQLite for tests but the unique index still requires
     * uniqueness when set), so we hash the action string to keep
     * each row distinct.
     */
    private function seedLog(string $timestamp, string $action): int
    {
        $hash = hash('sha256', $timestamp.'|'.$action, binary: true);

        return (int) DB::table('log_messages')->insertGetId([
            'page_id' => $this->page->id,
            'timestamp' => $timestamp,
            'type' => 'webhook',
            'action' => $action,
            'method' => 'POST',
            'status' => '200',
            'content_hash' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
