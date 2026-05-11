<?php

namespace Tests\Feature;

use App\Console\Commands\BexArchiveCold;
use App\Models\Application;
use App\Models\LogArchiveManifest;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ColdLogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Coverage for the {@see ColdLogReader} service (G20):
 *
 *   - manifest lookup respects the requested date window,
 *   - the reader returns the archived rows that fall inside the
 *     requested ISO8601 window when manifest entries exist,
 *   - additional facet filters (type/action/status) compose with
 *     the time range,
 *   - archived row count summed via `archivedRowCount` matches
 *     the manifest sum (used by the Manage page's hint).
 *
 * The service operates entirely against the faked `cold-logs` disk
 * + `log_archive_manifest` table; we don't have to spin up the
 * archive command itself, just write a representative blob and
 * manifest row by hand. (One end-to-end flow test lives in
 * BexArchiveColdTest.)
 */
class ColdLogReaderTest extends TestCase
{
    use RefreshDatabase;

    private Subscription $sub;

    private Page $page;

    private ColdLogReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(BexArchiveCold::DISK);

        $user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-cold-reader',
            'user_id' => $user->id,
            'name' => 'Cold Reader Org',
        ]);

        $app = Application::create([
            'id' => 'app-cold-reader',
            'organization_id' => $org->id,
            'name' => 'Cold Reader App',
        ]);

        $this->sub = Subscription::create([
            'id' => 'sub-cold-reader',
            'application_id' => $app->id,
            'name' => 'Cold Reader Sub',
            'environment' => 'production',
        ]);

        $this->page = Page::create([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $this->sub->id,
        ]);

        $this->reader = app(ColdLogReader::class);
    }

    public function test_returns_merged_rows_for_range_spanning_archived_days(): void
    {
        $day1 = Carbon::create(2026, 4, 10);
        $day2 = Carbon::create(2026, 4, 11);

        $this->writeArchive($day1, [
            $this->makeEntry($day1->copy()->setTime(9, 0, 0), 'created'),
            $this->makeEntry($day1->copy()->setTime(15, 0, 0), 'updated'),
        ]);
        $this->writeArchive($day2, [
            $this->makeEntry($day2->copy()->setTime(11, 0, 0), 'deleted'),
        ]);

        // Range covers both days end-to-end.
        $rows = $this->reader->fetchRowsForRange(
            page: $this->page,
            fromIso: '2026-04-10T00:00:00Z',
            toIso: '2026-04-12T00:00:00Z',
        );

        $this->assertSame(3, $rows->count());
        // Sorted newest first; the first row should be the latest
        // timestamp (day 2 11:00).
        $this->assertSame('deleted', $rows->first()['action']);
    }

    public function test_filters_rows_outside_requested_window(): void
    {
        $day = Carbon::create(2026, 4, 10);

        $this->writeArchive($day, [
            // 04:00 — before the requested 06:00 lower bound.
            $this->makeEntry($day->copy()->setTime(4, 0, 0), 'too-early'),
            // 12:00 — inside the window.
            $this->makeEntry($day->copy()->setTime(12, 0, 0), 'in-range'),
            // 22:00 — after the requested 18:00 upper bound.
            $this->makeEntry($day->copy()->setTime(22, 0, 0), 'too-late'),
        ]);

        $rows = $this->reader->fetchRowsForRange(
            page: $this->page,
            fromIso: '2026-04-10T06:00:00Z',
            toIso: '2026-04-10T18:00:00Z',
        );

        $this->assertSame(['in-range'], $rows->pluck('action')->all());
    }

    public function test_additional_filters_compose_with_range(): void
    {
        $day = Carbon::create(2026, 4, 10);

        $this->writeArchive($day, [
            $this->makeEntry($day->copy()->setTime(8, 0, 0), 'Reservation created'),
            $this->makeEntry($day->copy()->setTime(9, 0, 0), 'Webhook delivered'),
            $this->makeEntry($day->copy()->setTime(10, 0, 0), 'Reservation updated'),
        ]);

        $rows = $this->reader->fetchRowsForRange(
            page: $this->page,
            fromIso: '2026-04-10T00:00:00Z',
            toIso: '2026-04-11T00:00:00Z',
            additionalFilters: ['entity' => 'Reservation'],
        );

        $this->assertSame(2, $rows->count());
        $this->assertSame(
            ['Reservation updated', 'Reservation created'],
            $rows->pluck('action')->all(),
        );
    }

    public function test_archived_row_count_sums_manifest_rows(): void
    {
        $day1 = Carbon::create(2026, 4, 10);
        $day2 = Carbon::create(2026, 4, 11);

        $this->writeArchive($day1, [
            $this->makeEntry($day1->copy()->setTime(8, 0, 0), 'a'),
            $this->makeEntry($day1->copy()->setTime(9, 0, 0), 'b'),
        ]);
        $this->writeArchive($day2, [
            $this->makeEntry($day2->copy()->setTime(8, 0, 0), 'c'),
            $this->makeEntry($day2->copy()->setTime(9, 0, 0), 'd'),
            $this->makeEntry($day2->copy()->setTime(10, 0, 0), 'e'),
        ]);

        $this->assertSame(5, $this->reader->archivedRowCount($this->sub->id));
    }

    public function test_manifest_lookup_respects_subscription_scope(): void
    {
        // Build a sibling subscription whose manifest rows must NOT
        // leak into the page-scoped query.
        $otherSub = Subscription::create([
            'id' => 'sub-cold-other',
            'application_id' => $this->page->application_id,
            'name' => 'Other Sub',
            'environment' => 'production',
        ]);

        LogArchiveManifest::create([
            'subscription_id' => $otherSub->id,
            'date' => '2026-04-10',
            's3_key' => BexArchiveCold::objectKey($otherSub->id, '2026-04-10'),
            'row_count' => 99,
            'archived_at' => now(),
        ]);

        $manifests = $this->reader->manifestsForRange(
            page: $this->page,
            fromIso: '2026-04-01T00:00:00Z',
            toIso: '2026-04-30T00:00:00Z',
        );

        $this->assertTrue($manifests->isEmpty(), 'sibling subscription manifests must not surface');
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function writeArchive(Carbon $date, array $entries): void
    {
        $key = BexArchiveCold::dateKey($this->sub->id, $date);
        $payload = BexArchiveCold::serializeJsonl($entries);

        Storage::disk(BexArchiveCold::DISK)->put($key, gzencode($payload, 6));

        LogArchiveManifest::create([
            'subscription_id' => $this->sub->id,
            'date' => $date->toDateString(),
            's3_key' => $key,
            'row_count' => count($entries),
            'archived_at' => now(),
        ]);
    }

    /**
     * Build a JSONL-shaped entry that mirrors what the archive
     * command writes. `id`/`page_id`/`content_hash` are required for
     * the cold reader's page-scope filter to behave correctly.
     */
    private function makeEntry(Carbon $timestamp, string $action): array
    {
        $iso = $timestamp->toIso8601String();
        $hash = hash('sha256', $iso.'|'.$action);

        return [
            'id' => crc32($iso.$action),
            'page_id' => (int) $this->page->id,
            'timestamp' => $iso,
            'type' => 'webhook',
            'action' => $action,
            'method' => 'POST',
            'path' => null,
            'status' => '200',
            'parameters' => null,
            'request' => null,
            'response' => null,
            'content_hash' => $hash,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
