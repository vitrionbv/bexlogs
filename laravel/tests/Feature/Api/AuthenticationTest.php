<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Authentication invariants for the api-platform REST API.
 *
 * The whole point of this exercise: every endpoint MUST require a
 * valid Sanctum personal access token (or a stateful session). A
 * regression here would expose org data to the public internet, so
 * each named test pins a separate failure mode (no token, bad
 * format, valid token works, docs page is also gated).
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_endpoint_returns_401_without_token(): void
    {
        $this->getJson('/api/organizations')
            ->assertStatus(401);
    }

    public function test_item_endpoint_returns_401_without_token(): void
    {
        $this->getJson('/api/organizations/anything')
            ->assertStatus(401);
    }

    public function test_openapi_docs_are_gated_by_auth(): void
    {
        // The Swagger UI page itself dumps the full schema if it's
        // reachable — keep it behind auth. Negotiate Accept so api-
        // platform doesn't try to render HTML for an anonymous probe.
        $this->getJson('/api/docs')
            ->assertStatus(401);
    }

    public function test_authenticated_user_can_hit_collection_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['read']);

        // api-platform exposes both `application/json` (a plain
        // array of items) and `application/ld+json` (the rich
        // envelope with totalItems / view / member). The test
        // targets JSON-LD because it gives us assertable envelope
        // fields. `getJson()` hard-codes `Accept: application/json`
        // in its server vars and that wins over `withHeader()`,
        // so we pass the override via the explicit headers
        // argument instead.
        $this
            ->getJson('/api/organizations', ['Accept' => 'application/ld+json'])
            ->assertOk()
            ->assertJsonStructure(['member', 'totalItems']);
    }

    public function test_bogus_bearer_token_is_rejected(): void
    {
        $this
            ->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/organizations')
            ->assertStatus(401);
    }
}
