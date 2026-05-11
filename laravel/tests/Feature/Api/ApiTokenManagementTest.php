<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The /settings/api-tokens flow — create/list/revoke + the
 * end-to-end "use the freshly-minted token against the API" path.
 *
 * The most important assertion here is that the *plaintext* token
 * comes back from `store` exactly once (via the session flash, not
 * the DB) and is usable as a Bearer token immediately after.
 */
class ApiTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_then_use_token_against_api(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->from('/settings/api-tokens')
            ->post('/settings/api-tokens', ['name' => 'integration-test'])
            ->assertRedirect('/settings/api-tokens');

        // Plaintext only appears in the session flash; never persisted.
        $plain = session('plain_text_token');
        $this->assertNotEmpty($plain);

        // Hash IS persisted — we should see exactly one row.
        $this->assertSame(1, PersonalAccessToken::query()->count());

        // The minted token actually authenticates against the API.
        $this
            ->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/organizations')
            ->assertOk();
    }

    public function test_revoke_token_immediately_blocks_further_use(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('to-be-revoked');

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/organizations')
            ->assertOk();

        $this->actingAs($user)
            ->delete('/settings/api-tokens/'.$token->accessToken->id)
            ->assertRedirect();

        $this->assertSame(0, PersonalAccessToken::query()->count());

        // Clear the leaked session/auth state from `actingAs()` above —
        // without this Sanctum's stateful fallback would still see a
        // valid session and the now-revoked Bearer token would still
        // appear "logged in" via the cookie/session leg of the guard
        // (the revoke kill-switch only kicks in once the Bearer token
        // is the sole auth on the request).
        $this->flushSession();
        auth()->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/organizations')
            ->assertStatus(401);
    }

    public function test_user_cannot_revoke_another_users_token(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $bobToken = $bob->createToken('bob-laptop')->accessToken;

        $this->actingAs($alice)
            ->delete('/settings/api-tokens/'.$bobToken->id)
            ->assertRedirect();

        // Bob's token is still alive — Alice's controller hit returned
        // success because we silently no-op (no leak about whether the
        // id exists), but the row is intact.
        $this->assertNotNull(PersonalAccessToken::query()->find($bobToken->id));
    }

    public function test_token_name_must_be_present(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/api-tokens')
            ->post('/settings/api-tokens', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }
}
