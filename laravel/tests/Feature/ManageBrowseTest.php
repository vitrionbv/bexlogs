<?php

namespace Tests\Feature;

use App\Models\BexSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManageBrowseTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURES = __DIR__.'/../Fixtures/booking_experts';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_browse_applications_returns_apps_when_session_exists(): void
    {
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications*' => Http::response(
                $this->fixture('app_list_1046_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1052/apps/developer/applications*' => Http::response('', 401),
        ]);

        $user = $this->userWithSession();

        $response = $this
            ->actingAs($user)
            ->getJson('/manage/browse/applications?environment=production');

        $response
            ->assertOk()
            ->assertJsonPath('requires_session_for', null)
            ->assertJsonPath('message', null)
            ->assertJsonCount(3, 'applications');

        $apps = $response->json('applications');
        $this->assertSame('Verbleif', collect($apps)->firstWhere('id', '90')['name']);
    }

    public function test_browse_organizations_for_application_groups_subs_by_customer_org(): void
    {
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications/90' => Http::response(
                $this->fixture('app_detail_1046_90_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications' => Http::response(
                $this->fixture('app_list_1046_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1052/apps/developer/applications*' => Http::response('', 401),
        ]);

        $user = $this->userWithSession();

        $response = $this
            ->actingAs($user)
            ->getJson('/manage/browse/applications/90/organizations?environment=production');

        $response
            ->assertOk()
            ->assertJsonPath('requires_session_for', null)
            ->assertJsonCount(36, 'organizations');

        $first = $response->json('organizations.0');
        $this->assertArrayHasKey('organization_id', $first);
        $this->assertArrayHasKey('organization_name', $first);
        $this->assertArrayHasKey('subscription_count', $first);
    }

    public function test_browse_subscriptions_filters_by_organization_id(): void
    {
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications/90' => Http::response(
                $this->fixture('app_detail_1046_90_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications' => Http::response(
                $this->fixture('app_list_1046_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1052/apps/developer/applications*' => Http::response('', 401),
        ]);

        $user = $this->userWithSession();

        // Without filter: all 36 subs.
        $unfiltered = $this->actingAs($user)
            ->getJson('/manage/browse/applications/90/subscriptions?environment=production');
        $unfiltered->assertOk()->assertJsonCount(36, 'subscriptions');

        // With filter: just BizStay The Hague (1 sub).
        $filtered = $this->actingAs($user)
            ->getJson('/manage/browse/applications/90/subscriptions'
                .'?environment=production&organization_id=name%3Abizstay-the-hague');

        $filtered
            ->assertOk()
            ->assertJsonCount(1, 'subscriptions')
            ->assertJsonPath('subscriptions.0.id', '9689')
            ->assertJsonPath('subscriptions.0.developer_organization_id', '1046');
    }

    public function test_browse_endpoints_return_requires_session_for_when_no_active_session(): void
    {
        // No HTTP fakes — endpoints must NOT call BookingExperts when
        // there's no session, otherwise they'd fail with a network error.
        Http::fake();

        $user = User::factory()->create();

        $tests = [
            ['/manage/browse/applications?environment=production', 'applications'],
            ['/manage/browse/applications/90/organizations?environment=production', 'organizations'],
            ['/manage/browse/applications/90/subscriptions?environment=production', 'subscriptions'],
        ];

        foreach ($tests as [$url, $listKey]) {
            $response = $this->actingAs($user)->getJson($url);

            $response
                ->assertOk()
                ->assertJsonPath('requires_session_for', 'production')
                ->assertJsonPath($listKey, [])
                ->assertJsonStructure(['message']);
        }

        Http::assertNothingSent();
    }

    public function test_invalid_environment_falls_back_to_production(): void
    {
        Http::fake([
            'https://app.bookingexperts.com/parks' => Http::response($this->fixture('parks.html'), 200),
            'https://app.bookingexperts.com/organizations/1046/apps/developer/applications*' => Http::response(
                $this->fixture('app_list_1046_page1.html'),
                200,
            ),
            'https://app.bookingexperts.com/organizations/1052/apps/developer/applications*' => Http::response('', 401),
        ]);

        $user = $this->userWithSession();

        // ?environment=evil falls through to production; the user has a
        // production session so the request succeeds with a populated list.
        $response = $this
            ->actingAs($user)
            ->getJson('/manage/browse/applications?environment=evil');

        $response
            ->assertOk()
            ->assertJsonPath('requires_session_for', null)
            ->assertJsonCount(3, 'applications');
    }

    private function userWithSession(string $env = 'production'): User
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

        return $user;
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(self::FIXTURES."/{$name}");
        if ($contents === false) {
            throw new \RuntimeException("Could not read fixture: {$name}");
        }

        return $contents;
    }
}
