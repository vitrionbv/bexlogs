<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The "you can only see your own data" guarantee.
 *
 * Every read query api-platform issues runs through
 * `App\Api\QueryExtension\OrganizationScopeExtension`, which walks
 * each resource back to a `user_id` and filters on the authenticated
 * user. These tests pin both halves of the contract:
 *
 *   1. The collection returns ONLY rows the user owns (other orgs
 *      stay invisible, no leakage through pagination).
 *   2. Hitting another user's id directly on the show endpoint 404s.
 *      We accept 404 (not 403) because revealing existence — even via
 *      a "forbidden" status — is itself a leak.
 *
 * Subscription is the canonical test target because the scope walks
 * three relations (subscription → application → organization → user),
 * so if any link in the chain breaks this test catches it.
 */
class OrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_only_sees_their_own_subscriptions(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceSub = $this->makeSubscription($alice, 'alice-sub');
        $bobSub = $this->makeSubscription($bob, 'bob-sub');

        Sanctum::actingAs($alice, ['read']);

        $response = $this
            ->getJson('/api/subscriptions', ['Accept' => 'application/ld+json'])
            ->assertOk();

        $names = collect($response->json('member'))->pluck('name')->all();

        $this->assertContains('alice-sub', $names);
        $this->assertNotContains('bob-sub', $names);

        // And the totalItems counter must match what we see — a stale
        // total would betray that other orgs exist even if their rows
        // don't surface in `member`.
        $this->assertSame(1, $response->json('totalItems'));
    }

    public function test_user_cannot_read_another_orgs_subscription_show(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $bobSub = $this->makeSubscription($bob, 'bob-sub');

        Sanctum::actingAs($alice, ['read']);

        $this
            ->getJson('/api/subscriptions/'.$bobSub->id, ['Accept' => 'application/ld+json'])
            ->assertNotFound();
    }

    public function test_user_can_read_their_own_subscription_show(): void
    {
        $alice = User::factory()->create();
        $sub = $this->makeSubscription($alice, 'alice-sub');

        Sanctum::actingAs($alice, ['read']);

        $this
            ->getJson('/api/subscriptions/'.$sub->id, ['Accept' => 'application/ld+json'])
            ->assertOk()
            ->assertJsonPath('name', 'alice-sub');
    }

    public function test_organization_endpoint_only_returns_own_orgs(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Organization::factory()->for($alice)->create(['name' => 'alice-co']);
        Organization::factory()->for($bob)->create(['name' => 'bob-co']);

        Sanctum::actingAs($alice, ['read']);

        $names = collect(
            $this
                ->getJson('/api/organizations', ['Accept' => 'application/ld+json'])
                ->assertOk()
                ->json('member')
        )->pluck('name')->all();

        $this->assertSame(['alice-co'], $names);
    }

    private function makeSubscription(User $owner, string $name): Subscription
    {
        $org = Organization::factory()->for($owner)->create();
        $app = Application::factory()->create(['organization_id' => $org->id]);

        return Subscription::factory()->create([
            'application_id' => $app->id,
            'name' => $name,
        ]);
    }
}
