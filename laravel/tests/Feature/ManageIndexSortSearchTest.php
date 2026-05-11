<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the /manage Inertia page's index() action — specifically the
 * sort + search + stable-ordering behavior added to address operators'
 * "subscription jumps when I edit it" complaint:
 *
 *   1. The eager-loaded nested cascade (org → app → sub) is ordered at
 *      every level with `id` as the final tiebreaker, so an UPDATE on a
 *      single subscription leaves the rendered order untouched. The
 *      previous code only ordered the top-level orgs by name; apps and
 *      subs came back in Postgres heap order, which an UPDATE on any
 *      sub row reshuffles.
 *
 *   2. The four `?sort=` modes (`name` default, `id`, `last_scraped`,
 *      `environment`) each apply a hard-coded ORDER BY fragment with an
 *      `id` tiebreaker. Unknown values fall back to `name`.
 *
 *   3. The `?q=` search filter is case-insensitive, matches across sub
 *      name / sub id / environment / app name / org name, and removes
 *      empty cascade branches (orgs whose every app filtered out, apps
 *      whose every sub filtered out) so the rendered tree never shows
 *      empty wrappers.
 *
 *   4. The `totals` summary chip reflects the UNFILTERED account, so an
 *      operator typing into the search box doesn't see a flickering
 *      "5 subs · now 3 · now 1" headline as they narrow the result.
 *
 * All assertions go through the Inertia response so we exercise the
 * actual page payload the Vue side will receive — including the new
 * `filters` and `totals` props that drive the toolbar.
 */
class ManageIndexSortSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Application $bexApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-stable',
            'user_id' => $this->user->id,
            'name' => 'Stable Org',
        ]);

        $this->bexApp = Application::create([
            'id' => 'app-stable',
            'organization_id' => $org->id,
            'name' => 'Stable App',
        ]);
    }

    /**
     * Bootstrap a deterministic ordering test set. Names chosen so the
     * `name` sort and the `id` sort disagree — that way an assertion on
     * "subs come back in IDs [1,2,3]" definitively means the controller
     * applied the id-sort path rather than just happening to match the
     * default name order.
     *
     *   id=10  name=Bravo   environment=production   last=10m ago
     *   id=2   name=Alpha   environment=production   last=1h ago
     *   id=100 name=Charlie environment=staging      last=null (never)
     */
    private function seedTriplet(): void
    {
        Subscription::create([
            'id' => '10',
            'application_id' => $this->bexApp->id,
            'name' => 'Bravo',
            'environment' => 'production',
            'last_scraped_at' => Carbon::now()->subMinutes(10),
        ]);

        Subscription::create([
            'id' => '2',
            'application_id' => $this->bexApp->id,
            'name' => 'Alpha',
            'environment' => 'production',
            'last_scraped_at' => Carbon::now()->subHour(),
        ]);

        Subscription::create([
            'id' => '100',
            'application_id' => $this->bexApp->id,
            'name' => 'Charlie',
            'environment' => 'staging',
            'last_scraped_at' => null,
        ]);
    }

    public function test_default_sort_is_name_with_id_tiebreaker(): void
    {
        $this->seedTriplet();

        $response = $this->actingAs($this->user)
            ->get(route('manage.index'))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Manage/Index')
            ->where('filters.sort', 'name')
            ->where('totals.subscriptions', 3)
            ->where('totals.organizations', 1)
            ->where('totals.applications', 1)
            ->where('organizations.0.applications.0.subscriptions.0.id', '2')   // Alpha
            ->where('organizations.0.applications.0.subscriptions.1.id', '10')  // Bravo
            ->where('organizations.0.applications.0.subscriptions.2.id', '100') // Charlie
        );
    }

    public function test_sort_by_id_orders_numerically_not_lexicographically(): void
    {
        $this->seedTriplet();

        // Lexicographic sort would yield ['10', '100', '2'] — the
        // numeric-aware sort (LENGTH(id), id) must yield ['2', '10',
        // '100']. This is the property that makes "sort by ID" the
        // operator-friendly escape hatch the user explicitly asked
        // for in the report.
        $response = $this->actingAs($this->user)
            ->get('/manage?sort=id')
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'id')
            ->where('organizations.0.applications.0.subscriptions.0.id', '2')
            ->where('organizations.0.applications.0.subscriptions.1.id', '10')
            ->where('organizations.0.applications.0.subscriptions.2.id', '100')
        );
    }

    public function test_sort_by_last_scraped_puts_never_scraped_at_the_bottom(): void
    {
        $this->seedTriplet();

        // Bravo (10m ago) → Alpha (1h ago) → Charlie (null/never).
        // The NULLS-LAST behavior is the protective bit: a fresh
        // subscription that hasn't been scraped yet shouldn't pretend
        // to be the oldest one when the operator sorts by recency.
        $response = $this->actingAs($this->user)
            ->get(route('manage.index', ['sort' => 'last_scraped']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'last_scraped')
            ->where('organizations.0.applications.0.subscriptions.0.id', '10')   // Bravo
            ->where('organizations.0.applications.0.subscriptions.1.id', '2')    // Alpha
            ->where('organizations.0.applications.0.subscriptions.2.id', '100')  // Charlie (null)
        );
    }

    public function test_sort_by_environment_groups_production_before_staging(): void
    {
        $this->seedTriplet();

        $response = $this->actingAs($this->user)
            ->get(route('manage.index', ['sort' => 'environment']))
            ->assertOk();

        // production block sorted by name (Alpha, Bravo) followed by
        // the staging block (Charlie alone).
        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'environment')
            ->where('organizations.0.applications.0.subscriptions.0.environment', 'production')
            ->where('organizations.0.applications.0.subscriptions.0.name', 'Alpha')
            ->where('organizations.0.applications.0.subscriptions.1.environment', 'production')
            ->where('organizations.0.applications.0.subscriptions.1.name', 'Bravo')
            ->where('organizations.0.applications.0.subscriptions.2.environment', 'staging')
            ->where('organizations.0.applications.0.subscriptions.2.name', 'Charlie')
        );
    }

    public function test_unknown_sort_value_falls_back_to_name(): void
    {
        // Defensive — a typo (`?sort=foo`) or hostile probe must not
        // 500 or apply an arbitrary ORDER BY. Same outcome as the
        // bare /manage URL.
        $this->seedTriplet();

        $response = $this->actingAs($this->user)
            ->get(route('manage.index', ['sort' => 'foo']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'name')
            ->where('organizations.0.applications.0.subscriptions.0.name', 'Alpha')
        );
    }

    public function test_editing_a_subscription_does_not_change_the_rendered_order(): void
    {
        // The original bug report: editing any field on a subscription
        // would visibly move that row to a different position after
        // Inertia's partial reload because the apps + subs eager-load
        // had no ORDER BY clause and Postgres returned heap order
        // (which an UPDATE can shuffle).
        //
        // We simulate the round-trip:
        //   1. Capture the rendered sub-id sequence before any edit.
        //   2. PATCH the middle subscription (touches the row +
        //      mutates `updated_at`, which is what historically
        //      moved it in the heap).
        //   3. Re-render and assert the sequence is byte-identical.
        //
        // The assertion is intentionally on the full ordered tuple,
        // not a count or set, so a regression that "kept the same
        // rows but in a different order" still fails the test.
        $this->seedTriplet();

        $first = $this->actingAs($this->user)
            ->get(route('manage.index'))
            ->assertOk();

        $orderBefore = collect($first->viewData('page')['props']['organizations'][0]['applications'][0]['subscriptions'])
            ->pluck('id')
            ->all();

        // Touch the middle row. `auto_scrape` is the smallest mutation
        // that exercises the same update path as a budget edit; it
        // bumps `updated_at` and (historically) shuffled the heap.
        $this->actingAs($this->user)
            ->patch(route('manage.subscriptions.update', '10'), ['auto_scrape' => true])
            ->assertRedirect();

        $second = $this->actingAs($this->user)
            ->get(route('manage.index'))
            ->assertOk();

        $orderAfter = collect($second->viewData('page')['props']['organizations'][0]['applications'][0]['subscriptions'])
            ->pluck('id')
            ->all();

        $this->assertSame(
            $orderBefore,
            $orderAfter,
            'Subscription order must be byte-identical before and after editing a single row.',
        );
    }

    public function test_search_filters_by_subscription_name_case_insensitive(): void
    {
        $this->seedTriplet();

        $response = $this->actingAs($this->user)
            ->get(route('manage.index', ['q' => 'AlP']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('filters.q', 'AlP')
            // Totals are over the UNFILTERED dataset so the summary
            // stays calm while the user types.
            ->where('totals.subscriptions', 3)
            // Filtered tree: only the Alpha row survives, wrapping
            // app + org pass through because their child contains it.
            ->where('organizations.0.applications.0.subscriptions', fn ($subs) => count($subs) === 1
                && $subs[0]['name'] === 'Alpha')
        );
    }

    public function test_search_matches_by_subscription_id(): void
    {
        // Operators often paste an id from another tab. Make sure the
        // search box handles that case explicitly.
        $this->seedTriplet();

        $response = $this->actingAs($this->user)
            ->get(route('manage.index', ['q' => '100']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('organizations.0.applications.0.subscriptions', fn ($subs) => count($subs) === 1
                && $subs[0]['id'] === '100'
                && $subs[0]['name'] === 'Charlie')
        );
    }

    public function test_search_with_no_matches_returns_empty_cascade_and_unchanged_totals(): void
    {
        // Empty tree (no orgs in the rendered list) tells the Vue side
        // to render the dedicated "no matches" empty state. The
        // totals chip stays at 3 so the operator can see what they're
        // filtering against.
        $this->seedTriplet();

        $response = $this->actingAs($this->user)
            ->get(route('manage.index', ['q' => 'definitely-nothing-matches-this']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('totals.subscriptions', 3)
            ->where('organizations', [])
        );
    }

    public function test_totals_auto_scrape_on_counts_subs_with_flag_set(): void
    {
        // The schema defaults `auto_scrape` to true, so we deliberately
        // turn the Alpha row OFF and leave Bravo + Charlie ON. That
        // gives a 3-subs / 2-auto split — enough to detect a regression
        // where the controller accidentally reports `count($subs)`
        // instead of the filtered subset.
        $this->seedTriplet();
        Subscription::query()->where('id', '2')->update(['auto_scrape' => false]);

        $response = $this->actingAs($this->user)
            ->get(route('manage.index'))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('totals.subscriptions', 3)
            ->where('totals.auto_scrape_on', 2)
        );
    }
}
