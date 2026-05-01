<?php

namespace Tests\Feature;

use App\Models\BexSession;
use App\Models\User;
use App\Services\BookingExpertsBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingExpertsBrowserTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../Fixtures/booking_experts';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_list_applications_walks_org_switcher_and_per_org_app_lists(): void
    {
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications*' => Http::response(
                $this->fixture('app_list_1046_page1.html'),
                200,
            ),
            // Org 1052 is one the user belongs to but has no developer
            // permission for — BE 401s and our service must skip it
            // silently rather than blowing up the whole list.
            'https://app.bookingexperts.com/organizations/1052/apps/developer/applications*' => Http::response(
                $this->fixture('app_list_1052_unauthorized.html'),
                401,
            ),
        ]);

        $apps = (new BookingExpertsBrowser($this->makeSession()))->listApplications();

        $this->assertCount(3, $apps);
        $ids = collect($apps)->pluck('id')->all();
        $this->assertEqualsCanonicalizing(['90', '132', '594'], $ids);

        $verbleif = collect($apps)->firstWhere('id', '90');
        $this->assertSame('Verbleif', $verbleif['name']);
        $this->assertSame('1046', $verbleif['organization_id']);
        $this->assertSame('Verbleif', $verbleif['organization_name']);

        // Sorted: alphabetical inside the (single) org bucket.
        $this->assertSame(['Homeassisant', 'Verbleif', 'Verbleif Beta'], collect($apps)->pluck('name')->all());
    }

    public function test_list_subscriptions_extracts_full_modal_template_list_from_real_html(): void
    {
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications*' => Http::sequence()
                ->push($this->fixture('app_list_1046_page1.html'), 200)
                ->push($this->fixture('app_detail_1046_90_page1.html'), 200),
            'https://app.bookingexperts.com/organizations/1052/apps/developer/applications*' => Http::response('', 401),
        ]);

        $subs = (new BookingExpertsBrowser($this->makeSession()))->listSubscriptionsForApplication('90');

        // The captured page embeds 36 unique customer subscribers in the
        // "Bekijk alle Installaties" modal template. PHP's DOMDocument
        // parses <template> children as regular DOM nodes so XPath finds
        // them all.
        $this->assertCount(36, $subs);

        $bizstay = collect($subs)->firstWhere('id', '9689');
        $this->assertNotNull($bizstay);
        $this->assertSame('BizStay The Hague', $bizstay['name']);
        $this->assertSame('name:bizstay-the-hague', $bizstay['organization_id']);
        $this->assertSame('1046', $bizstay['developer_organization_id']);
        $this->assertSame('Verbleif', $bizstay['developer_organization_name']);
    }

    public function test_subscriptions_pagination_walks_rel_next_links(): void
    {
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            // First request to applications/90 returns page 1 with a
            // rel=next link to ?page=2; ?page=2 returns page 2 with no
            // further next link. The browser should walk both and
            // aggregate the 4 rows.
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications' => Http::response(
                $this->fixture('app_list_1046_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications/90?page=2' => Http::response(
                $this->fixture('paginated_subs_page2.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications/90' => Http::response(
                $this->fixture('paginated_subs_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1052/apps/developer/applications*' => Http::response('', 401),
        ]);

        $subs = (new BookingExpertsBrowser($this->makeSession()))->listSubscriptionsForApplication('90');

        $ids = collect($subs)->pluck('id')->all();
        sort($ids);
        $this->assertSame(['100', '200', '300', '400'], $ids, 'should aggregate both pages');

        $names = collect($subs)->pluck('name')->all();
        $this->assertSame(['Org A', 'Org B', 'Org C', 'Org D'], $names);
    }

    public function test_organizations_for_application_dedupes_and_groups_subscription_count(): void
    {
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications' => Http::response(
                $this->fixture('app_list_1046_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications/90' => Http::response(
                $this->fixture('app_detail_1046_90_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1052/apps/developer/applications*' => Http::response('', 401),
        ]);

        $orgs = (new BookingExpertsBrowser($this->makeSession()))->listOrganizationsWithSubscriptionsForApplication('90');

        $this->assertCount(36, $orgs, 'each customer appears once per app');
        // Sort by org name (alphabetical, case-insensitive). First org
        // in the captured HTML is "'t Hooge Holt" (apostrophe-prefixed).
        $first = $orgs[0];
        $this->assertSame('name:t-hooge-holt', $first['organization_id']);
        $this->assertSame("'t Hooge Holt", $first['organization_name']);
        $this->assertSame(1, $first['subscription_count']);
        $this->assertCount(1, $first['subscriptions']);
        $this->assertSame('8135', $first['subscriptions'][0]['id']);
    }

    public function test_list_applications_handles_401_gracefully(): void
    {
        // Both dev-orgs return 401: parser should return [] without
        // throwing.
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            'https://app.bookingexperts.com/organizations/*/apps/developer/applications*' => Http::response('', 401),
        ]);

        $apps = (new BookingExpertsBrowser($this->makeSession()))->listApplications();

        $this->assertSame([], $apps);
    }

    private function makeSession(string $env = 'production'): BexSession
    {
        $user = User::factory()->create();

        $session = new BexSession;
        $session->user_id = $user->id;
        $session->environment = $env;
        $session->cookies = [
            ['name' => '_bex_session', 'value' => 'fake', 'domain' => 'app.bookingexperts.com'],
        ];
        $session->captured_at = now();
        $session->save();

        return $session->refresh();
    }

    private function fixture(string $name): string
    {
        $path = self::FIXTURES."/{$name}";
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Could not read fixture: {$path}");
        }

        return $contents;
    }
}
