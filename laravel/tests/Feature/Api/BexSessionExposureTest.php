<?php

namespace Tests\Feature\Api;

use App\Models\BexSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BexSession is the only resource on the API that carries auth-bearing
 * BookingExperts cookies. Leaking those would let anyone with a token
 * masquerade as the operator. The model is locked down by an allow-list
 * (`$visible`) — these tests pin the contract from the API side.
 *
 * Critical assertions:
 *   - Neither the raw cookies array nor the encrypted blob ever appears
 *     anywhere in the response body, on either collection or item routes.
 *   - The unique cookie value seeded by the factory is searched for
 *     literally in the response, so a future bug that base64s it or
 *     wraps it in an envelope is still caught.
 */
class BexSessionExposureTest extends TestCase
{
    use RefreshDatabase;

    public function test_bex_session_collection_never_exposes_cookies(): void
    {
        $user = User::factory()->create();
        BexSession::factory()->for($user)->create([
            'account_email' => 'operator@example.test',
        ]);

        Sanctum::actingAs($user, ['read']);

        $response = $this
            ->getJson('/api/bex-sessions', ['Accept' => 'application/ld+json'])
            ->assertOk();
        $rows = $response->json('member');
        $this->assertCount(1, $rows);

        $row = $rows[0];

        // The session itself is exposed (so the operator can see it
        // exists and when it was captured), but every cookie-shaped
        // field MUST be absent.
        $this->assertArrayNotHasKey('cookies', $row);
        $this->assertArrayNotHasKey('cookiesEncrypted', $row);
        $this->assertArrayNotHasKey('cookies_encrypted', $row);

        // Raw body sweep — defends against the next bug, where someone
        // renames the field or wraps it in a sub-object. The seed value
        // is the cookie payload from BexSessionFactory.
        $this->assertStringNotContainsString('super-secret-cookie-value', $response->getContent());
        $this->assertStringNotContainsString('_app_session', $response->getContent());

        // Whitelist of fields we DO expose, so a regression that adds a
        // new column without thinking about API exposure fails this
        // test loudly.
        $expected = ['id', 'environment', 'accountEmail', 'accountName', 'capturedAt', 'lastValidatedAt', 'expiredAt'];
        $allowed = array_merge($expected, ['@id', '@type', '@context']);
        foreach (array_keys($row) as $key) {
            $this->assertContains(
                $key,
                $allowed,
                "Unexpected BexSession field [{$key}] exposed via API. Update \$visible on BexSession only after deliberate review.",
            );
        }
    }

    public function test_bex_session_show_never_exposes_cookies(): void
    {
        $user = User::factory()->create();
        $session = BexSession::factory()->for($user)->create();

        Sanctum::actingAs($user, ['read']);

        $response = $this
            ->getJson('/api/bex-sessions/'.$session->id, ['Accept' => 'application/ld+json'])
            ->assertOk();

        $this->assertArrayNotHasKey('cookies', $response->json());
        $this->assertArrayNotHasKey('cookiesEncrypted', $response->json());
        $this->assertArrayNotHasKey('cookies_encrypted', $response->json());

        $this->assertStringNotContainsString('super-secret-cookie-value', $response->getContent());
    }
}
