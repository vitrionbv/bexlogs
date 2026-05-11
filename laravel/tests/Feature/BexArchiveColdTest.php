<?php

namespace Tests\Feature;

use App\Console\Commands\BexArchiveCold;
use App\Models\Application;
use App\Models\LogArchiveManifest;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Coverage for the `bex:archive-cold` command (G20):
 *
 *   - aged-out rows are written to a correctly-keyed `.jsonl.gz`
 *     object on the faked `cold-logs` disk,
 *   - a manifest entry is recorded for (subscription_id, date),
 *   - the source rows are deleted from log_messages once the
 *     upload + manifest write succeed,
 *   - re-archiving the same day merges with the existing object
 *     (idempotent retry path) — verified via dedup on
 *     (page_id, content_hash),
 *   - subs with NULL `archive_after_days` are skipped entirely.
 *
 * The faked `cold-logs` disk is mandatory; this test never touches
 * Hetzner. The fake survives across multiple `Storage::disk()` calls
 * within the same test, so the merge-existing-object branch can be
 * exercised by running the command twice in succession.
 */
class BexArchiveColdTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $sub;

    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(BexArchiveCold::DISK);

        $user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-archive',
            'user_id' => $user->id,
            'name' => 'Archive Org',
        ]);

        $app = Application::create([
            'id' => 'app-archive',
            'organization_id' => $org->id,
            'name' => 'Archive App',
        ]);

        $this->sub = Subscription::create([
            'id' => 'sub-archive',
            'application_id' => $app->id,
            'name' => 'Archive Sub',
            'environment' => 'production',
        ]);

        $this->page = Page::create([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $this->sub->id,
        ]);
    }

    public function test_writes_object_with_canonical_key_and_records_manifest(): void
    {
        $this->sub->update(['archive_after_days' => 30]);

        // Seed two old rows on the same UTC date so they collapse
        // into a single .jsonl.gz object.
        $date = Carbon::now()->subDays(60)->utc();
        $iso = $date->copy()->setTime(12, 0, 0)->toIso8601String();
        $iso2 = $date->copy()->setTime(13, 0, 0)->toIso8601String();

        $this->seedLog($iso, 'created', 'a');
        $this->seedLog($iso2, 'updated', 'b');

        $this->artisan('bex:archive-cold')->assertExitCode(0);

        $expectedKey = sprintf(
            'logs/%s/%s/%s/%s.jsonl.gz',
            $this->sub->id,
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
        );

        Storage::disk(BexArchiveCold::DISK)->assertExists($expectedKey);

        $manifest = LogArchiveManifest::query()
            ->where('subscription_id', $this->sub->id)
            ->where('date', $date->toDateString())
            ->first();
        $this->assertNotNull($manifest, 'manifest row should exist for the archived day');
        $this->assertSame($expectedKey, $manifest->s3_key);
        $this->assertSame(2, (int) $manifest->row_count);

        // Source rows are gone.
        $remaining = DB::table('log_messages')
            ->where('page_id', $this->page->id)
            ->count();
        $this->assertSame(0, $remaining);

        // The on-disk blob round-trips through gzdecode → JSONL.
        $raw = Storage::disk(BexArchiveCold::DISK)->get($expectedKey);
        $payload = gzdecode($raw);
        $this->assertNotFalse($payload);
        $entries = BexArchiveCold::parseJsonl($payload);
        $this->assertCount(2, $entries);
        $this->assertSame(['created', 'updated'], array_column($entries, 'action'));
    }

    public function test_skips_subscriptions_with_null_archive_after(): void
    {
        // Default leaves archive_after_days NULL → the command must
        // never write a thing.
        $this->seedLog(Carbon::now()->subYear()->toIso8601String(), 'unarchived', 'c');

        $this->artisan('bex:archive-cold')->assertExitCode(0);

        $this->assertSame(0, LogArchiveManifest::query()->count());
        $this->assertSame(1, DB::table('log_messages')->count());
    }

    public function test_re_archive_merges_with_existing_object_via_dedup(): void
    {
        $this->sub->update(['archive_after_days' => 30]);

        $date = Carbon::now()->subDays(45)->utc();

        // First run archives row A.
        $iso1 = $date->copy()->setTime(10, 0, 0)->toIso8601String();
        $this->seedLog($iso1, 'first-batch', 'a');
        $this->artisan('bex:archive-cold')->assertExitCode(0);

        // Second run: a fresh row B on the same UTC date that
        // shouldn't have been archived first time around (e.g.
        // arrived during the cron tick). The merge path should
        // pick it up and combine with the existing object.
        $iso2 = $date->copy()->setTime(11, 0, 0)->toIso8601String();
        $this->seedLog($iso2, 'second-batch', 'b');
        $this->artisan('bex:archive-cold')->assertExitCode(0);

        $manifest = LogArchiveManifest::query()
            ->where('subscription_id', $this->sub->id)
            ->where('date', $date->toDateString())
            ->first();
        $this->assertNotNull($manifest);
        $this->assertSame(2, (int) $manifest->row_count);

        $raw = Storage::disk(BexArchiveCold::DISK)->get($manifest->s3_key);
        $entries = BexArchiveCold::parseJsonl(gzdecode($raw));
        $this->assertCount(2, $entries);

        $actions = collect($entries)->pluck('action')->all();
        sort($actions);
        $this->assertSame(['first-batch', 'second-batch'], $actions);
    }

    /**
     * Insert a single log_messages row keyed off the action string
     * so each row's content_hash is unique. Returns the inserted id.
     */
    private function seedLog(string $timestamp, string $action, string $hashSeed): int
    {
        $hash = hash('sha256', $timestamp.'|'.$action.'|'.$hashSeed, binary: true);

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
