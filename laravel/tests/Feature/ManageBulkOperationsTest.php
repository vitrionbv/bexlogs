<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F17 bulk operations: the three endpoints (bulkUpdate / bulkDelete /
 * bulkEnqueueScrape) on `ManageController` that the Manage page's
 * sticky toolbar talks to. The matching per-sub endpoints are covered
 * by ManageEnqueueScrapeTest etc.; this file focuses on the things
 * the bulk path has that the per-row path doesn't:
 *
 *   - per-sub authorisation runs against the request set in one query
 *     and silently drops unauthorised ids
 *   - the validator mirrors the per-sub one (same field names + bounds)
 *   - bulk scrape respects ScrapeEnqueueGuard per row, so a mixed
 *     allowed/denied set produces a structured tally
 *   - AuditLogger emits one row per touched sub with old/new payloads
 */
class ManageBulkOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private Subscription $subA;

    private Subscription $subB;

    /**
     * A third subscription owned by `$otherUser`. Used to assert that
     * the bulk endpoints silently drop unauthorized ids rather than
     * 403-ing the whole batch.
     */
    private Subscription $subForeign;

    private BexSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->subA = $this->makeSub($this->user, name: 'Alpha');
        $this->subB = $this->makeSub($this->user, name: 'Bravo');
        $this->subForeign = $this->makeSub($this->otherUser, name: 'NotYours');

        $this->session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);
    }

    private function makeSub(User $owner, string $name): Subscription
    {
        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $owner->id,
            'name' => "Org for {$name}",
        ]);
        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => "App for {$name}",
        ]);

        // ->fresh() pulls DB defaults (auto_scrape, scrape interval,
        // budget knobs) into the model so the observer's `getOriginal`
        // sees the right starting state for diff payloads.
        return Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => $name,
            'environment' => 'production',
        ])->fresh();
    }

    public function test_bulk_update_applies_to_listed_subs_and_emits_audit_rows(): void
    {
        $this->assertTrue($this->subA->auto_scrape);
        $this->assertTrue($this->subB->auto_scrape);

        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->patch(route('manage.subscriptions.bulk-update'), [
                'subscription_ids' => [$this->subA->id, $this->subB->id],
                'auto_scrape' => false,
            ]);

        $response->assertRedirect(route('manage.index'));
        $response->assertSessionHas('status', 'bulk-update-applied');

        $this->assertFalse($this->subA->refresh()->auto_scrape);
        $this->assertFalse($this->subB->refresh()->auto_scrape);

        // Exactly two audit rows, one per touched sub. Each row uses
        // `subscription.auto_scrape_toggled` (controller picks that
        // action when the only changed key is `auto_scrape`).
        $rows = AuditLog::query()
            ->where('action', 'subscription.auto_scrape_toggled')
            ->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$this->subA->id, $this->subB->id],
            $rows->pluck('subject_id')->all(),
        );

        foreach ($rows as $row) {
            $this->assertSame(['auto_scrape' => true], $row->payload['old']);
            $this->assertSame(['auto_scrape' => false], $row->payload['new']);
        }
    }

    public function test_bulk_update_silently_drops_unauthorised_ids(): void
    {
        $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->patch(route('manage.subscriptions.bulk-update'), [
                'subscription_ids' => [
                    $this->subA->id,
                    $this->subForeign->id, // owned by otherUser
                ],
                'auto_scrape' => false,
            ])
            ->assertRedirect(route('manage.index'));

        // Owned sub got the update; foreign sub is untouched.
        $this->assertFalse($this->subA->refresh()->auto_scrape);
        $this->assertTrue($this->subForeign->refresh()->auto_scrape);
        // Only the owned sub emits an audit row.
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'subscription.auto_scrape_toggled')->count(),
        );
    }

    public function test_bulk_update_rejects_out_of_range_field(): void
    {
        $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->patch(route('manage.subscriptions.bulk-update'), [
                'subscription_ids' => [$this->subA->id],
                'max_concurrent_jobs' => 999,
            ])
            ->assertSessionHasErrors(['max_concurrent_jobs']);

        // Nothing changed on the sub when validation fires.
        $this->assertSame(1, $this->subA->refresh()->max_concurrent_jobs);
    }

    public function test_bulk_update_with_no_touched_fields_is_a_noop(): void
    {
        $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->patch(route('manage.subscriptions.bulk-update'), [
                'subscription_ids' => [$this->subA->id],
            ])
            ->assertSessionHas('status', 'bulk-update-noop');

        $this->assertSame(
            0,
            AuditLog::query()->whereIn('action', [
                'subscription.updated',
                'subscription.budget_updated',
                'subscription.auto_scrape_toggled',
            ])->count(),
        );
    }

    public function test_bulk_delete_removes_owned_rows_and_audits_each(): void
    {
        $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->delete(route('manage.subscriptions.bulk-delete'), [
                'subscription_ids' => [$this->subA->id, $this->subB->id, $this->subForeign->id],
            ])
            ->assertRedirect(route('manage.index'))
            ->assertSessionHas('status', 'bulk-delete-applied');

        $this->assertNull($this->subA->fresh());
        $this->assertNull($this->subB->fresh());
        // Foreign sub survives.
        $this->assertNotNull($this->subForeign->fresh());

        $this->assertSame(
            2,
            AuditLog::query()->where('action', 'subscription.deleted')->count(),
        );
    }

    public function test_bulk_scrape_respects_per_sub_guard_and_returns_tally(): void
    {
        // Wedge subA's slot with a running job inside the spacing
        // window so the guard denies it. subB should still queue.
        Carbon::setTestNow('2026-05-11T20:05:00Z');
        ScrapeJob::create([
            'subscription_id' => $this->subA->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => Carbon::parse('2026-05-11T20:00:00Z'), // 5min ago, spacing=10min
            'last_heartbeat_at' => Carbon::parse('2026-05-11T20:00:00Z'),
        ]);

        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.bulk-scrape'), [
                'subscription_ids' => [$this->subA->id, $this->subB->id],
            ]);

        $response->assertSessionHas('status', 'bulk-scrape-applied');
        $response->assertSessionHas('bulk_scrape_queued', 1);
        $response->assertSessionHas('bulk_scrape_skipped', 1);

        // subA still has its pre-existing running job + nothing new;
        // subB has one fresh queued row.
        $this->assertSame(
            1,
            ScrapeJob::query()->where('subscription_id', $this->subA->id)->count(),
        );
        $this->assertSame(
            1,
            ScrapeJob::query()->where('subscription_id', $this->subB->id)->count(),
        );

        // Audit: subA → scrape.denied, subB → scrape.manual_triggered.
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'scrape.denied')
                ->where('subject_id', $this->subA->id)
                ->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'scrape.manual_triggered')
                ->where('subject_id', $this->subB->id)
                ->count(),
        );

        Carbon::setTestNow();
    }

    public function test_bulk_scrape_skips_when_no_session(): void
    {
        // Strip every session for this user so every row hits the
        // "no_session" branch.
        BexSession::query()->where('user_id', $this->user->id)->delete();

        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.bulk-scrape'), [
                'subscription_ids' => [$this->subA->id, $this->subB->id],
            ]);

        $response->assertSessionHas('bulk_scrape_queued', 0);
        $response->assertSessionHas('bulk_scrape_skipped', 2);
        $response->assertSessionHas('bulk_scrape_no_session', 2);

        $this->assertSame(
            0,
            ScrapeJob::query()
                ->whereIn('subscription_id', [$this->subA->id, $this->subB->id])
                ->count(),
        );
    }
}
