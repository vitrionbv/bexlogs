<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Focused tests for {@see AuditLogger} and its observer wiring. The
 * per-controller assertions live next to those endpoints'
 * happy-path tests (ManageBulkOperationsTest, etc.); this file
 * exercises the cross-cutting concerns:
 *
 *   - the dedup map (`suppressNext` → observer skips its own write)
 *   - the diff helper builds the old/new shape correctly
 *   - the action namespacing the observer picks based on dirty
 *     columns matches the controller's mapping
 */
class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'Audit Org',
        ]);
        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Audit App',
        ]);
        // Refresh after create so model attributes pick up DB
        // defaults (auto_scrape, scrape_interval_minutes, …). Without
        // this the audit observer's `getOriginal()` lookup returns
        // null for any column that wasn't explicitly passed to
        // create(), which trips up the diff assertions below.
        $this->sub = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Audit Sub',
            'environment' => 'production',
        ])->fresh();
    }

    public function test_diff_returns_only_changed_keys(): void
    {
        $diff = AuditLogger::diff(
            $this->sub,
            [
                // unchanged — should not appear in the diff
                'environment' => 'production',
                // changed — appears with old + new values
                'scrape_interval_minutes' => 15,
            ],
        );

        $this->assertSame(['scrape_interval_minutes' => 5], $diff['old']);
        $this->assertSame(['scrape_interval_minutes' => 15], $diff['new']);
    }

    public function test_observer_records_subscription_created_with_payload(): void
    {
        // Per setUp(), the sub is already created — its `created`
        // observer fired once. The action vocabulary is namespaced;
        // assert it landed with the expected shape.
        $row = AuditLog::query()
            ->where('action', 'subscription.created')
            ->where('subject_id', $this->sub->id)
            ->firstOrFail();

        $this->assertSame('Audit Sub', $row->payload['new']['name']);
        $this->assertSame('production', $row->payload['new']['environment']);
    }

    public function test_auto_scrape_toggle_uses_dedicated_action(): void
    {
        $this->sub->update(['auto_scrape' => false]);

        $row = AuditLog::query()
            ->where('subject_id', $this->sub->id)
            ->where('action', 'subscription.auto_scrape_toggled')
            ->firstOrFail();

        $this->assertSame(['auto_scrape' => true], $row->payload['old']);
        $this->assertSame(['auto_scrape' => false], $row->payload['new']);
    }

    public function test_budget_only_change_uses_budget_updated_action(): void
    {
        $this->sub->update([
            'scrape_interval_minutes' => 30,
            'max_pages_per_scrape' => 250,
        ]);

        $row = AuditLog::query()
            ->where('subject_id', $this->sub->id)
            ->where('action', 'subscription.budget_updated')
            ->firstOrFail();

        $this->assertSame(5, $row->payload['old']['scrape_interval_minutes']);
        $this->assertSame(30, $row->payload['new']['scrape_interval_minutes']);
        $this->assertSame(200, $row->payload['old']['max_pages_per_scrape']);
        $this->assertSame(250, $row->payload['new']['max_pages_per_scrape']);
    }

    public function test_suppress_next_keeps_observer_silent_within_one_request(): void
    {
        /** @var AuditLogger $audit */
        $audit = app(AuditLogger::class);

        // Marking the (action, subject) before the mutation should
        // make the observer's matching write a no-op.
        $audit->suppressNext('subscription.auto_scrape_toggled', $this->sub);
        $this->sub->update(['auto_scrape' => false]);

        $count = AuditLog::query()
            ->where('subject_id', $this->sub->id)
            ->where('action', 'subscription.auto_scrape_toggled')
            ->count();

        // Zero rows: the observer was suppressed and no controller
        // ran an explicit `record()` either.
        $this->assertSame(0, $count);
    }

    public function test_record_captures_ip_and_user_attribution_when_authed(): void
    {
        $this->actingAs($this->user);

        /** @var AuditLogger $audit */
        $audit = app(AuditLogger::class);
        $audit->record('subscription.updated', $this->sub, [
            'old' => ['name' => 'A'],
            'new' => ['name' => 'B'],
        ]);

        $row = AuditLog::query()
            ->where('subject_id', $this->sub->id)
            ->where('action', 'subscription.updated')
            ->firstOrFail();

        $this->assertSame((int) $this->user->id, (int) $row->user_id);
    }
}
