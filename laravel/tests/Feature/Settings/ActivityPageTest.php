<?php

namespace Tests\Feature\Settings;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia-flavoured tests for the Activity page (F18). Uses
 * `AssertableInertia` so we don't have to chase X-Inertia version
 * mismatches in the assertion path — the framework's testing helper
 * stubs the version check.
 */
class ActivityPageTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);

        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->alice->id,
            'name' => 'Filter Test Org',
        ]);
        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Filter Test App',
        ]);
        // Refresh after create so model attributes pick up DB defaults
        // (auto_scrape, scrape_interval_minutes, etc.) — the audit
        // observer's `getOriginal()` lookup would otherwise see nulls.
        $this->sub = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Filter Test Sub',
            'environment' => 'production',
        ])->fresh();

        // The Subscription factory above fires the `created` observer,
        // which lands one `subscription.created` row in audit_logs.
        // Wipe the table so the per-test fixtures own a known-good
        // baseline of exactly the rows the test seeds itself.
        AuditLog::query()->delete();

        /** @var AuditLogger $audit */
        $audit = app(AuditLogger::class);
        $audit->resetSeen();

        Carbon::setTestNow('2026-05-10T10:00:00Z');
        $this->actingAs($this->alice);
        $audit->record('subscription.budget_updated', $this->sub, [
            'old' => ['scrape_interval_minutes' => 5],
            'new' => ['scrape_interval_minutes' => 15],
        ]);

        Carbon::setTestNow('2026-05-10T11:00:00Z');
        $audit->record('subscription.auto_scrape_toggled', $this->sub, [
            'old' => ['auto_scrape' => true],
            'new' => ['auto_scrape' => false],
        ]);

        Carbon::setTestNow('2026-05-11T10:00:00Z');
        $this->actingAs($this->bob);
        $audit->record('subscription.deleted', $this->sub, [
            'old' => ['name' => $this->sub->name],
        ]);

        Carbon::setTestNow();
    }

    public function test_unfiltered_returns_all_rows_newest_first(): void
    {
        $this->actingAs($this->alice)
            ->get(route('activity.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/Activity')
                ->where('logs.data.0.action', 'subscription.deleted')
                ->where('logs.data.1.action', 'subscription.auto_scrape_toggled')
                ->where('logs.data.2.action', 'subscription.budget_updated')
                ->where('logs.meta.total', 3),
            );
    }

    public function test_user_filter_narrows_to_one_actor(): void
    {
        $this->actingAs($this->alice)
            ->get(route('activity.index', ['user_id' => $this->bob->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.meta.total', 1)
                ->where('logs.data.0.action', 'subscription.deleted')
                ->where('logs.data.0.user.id', $this->bob->id),
            );
    }

    public function test_action_multi_select_filter(): void
    {
        $this->actingAs($this->alice)
            ->get(route('activity.index', [
                'actions' => [
                    'subscription.budget_updated',
                    'subscription.auto_scrape_toggled',
                ],
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.meta.total', 2),
            );
    }

    public function test_date_range_filter(): void
    {
        $this->actingAs($this->alice)
            ->get(route('activity.index', ['from' => '2026-05-11T00:00:00Z']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.meta.total', 1)
                ->where('logs.data.0.action', 'subscription.deleted'),
            );
    }

    public function test_subject_filter_with_specific_id(): void
    {
        // All three seeded rows target the same Subscription so the
        // count doesn't drop — but this still exercises the
        // morph-class allow-list lookup.
        $this->actingAs($this->alice)
            ->get(route('activity.index', [
                'subject_type' => 'Subscription',
                'subject_id' => $this->sub->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.meta.total', 3),
            );
    }

    public function test_unknown_subject_type_is_ignored(): void
    {
        $this->actingAs($this->alice)
            ->get(route('activity.index', [
                'subject_type' => 'NotAModel',
                'subject_id' => $this->sub->id,
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('logs.meta.total', 3),
            );
    }

    public function test_facets_expose_known_actions_and_users(): void
    {
        // `Inertia\Testing\AssertableInertia::where(key, closure)`
        // wraps a plain array prop in `Illuminate\Support\Collection`
        // before it hits the closure (see Matching::where in
        // Illuminate\Testing\Fluent\Concerns), so we type-hint and
        // call `containsStrict()` rather than `in_array()`.
        $this->actingAs($this->alice)
            ->get(route('activity.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $page->where(
                    'facets.actions',
                    fn (Collection $actions) => $actions->containsStrict('subscription.deleted'),
                );
                $page->where(
                    'facets.subject_types',
                    fn (Collection $types) => $types->containsStrict('Subscription'),
                );
            });
    }
}
