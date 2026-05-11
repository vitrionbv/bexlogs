<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Token-management UI: list / create / revoke. The most important
 * invariant here is "you can only see/manage your OWN tokens" — the
 * controller scopes via `$request->user()->tokens()`, but the test
 * pins it down by trying to revoke another user's token via id.
 */
class ApiTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings/api-tokens')
            ->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/api-tokens')->assertRedirect('/login');
    }

    public function test_creating_a_token_returns_plaintext_via_flash_once(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from('/settings/api-tokens')
            ->post('/settings/api-tokens', ['name' => 'CI deploy']);

        $response->assertRedirect('/settings/api-tokens')
            ->assertSessionHas('plain_text_token');

        $this->assertSame(1, $user->tokens()->where('name', 'CI deploy')->count());

        // The plaintext is delivered as a one-shot session flash; a
        // second visit (without re-creating) must not surface it.
        $followUp = $this->actingAs($user)->get('/settings/api-tokens');
        $followUp->assertOk()->assertSessionMissing('plain_text_token');
    }

    public function test_token_name_must_be_unique_per_user(): void
    {
        $user = User::factory()->create();
        $user->createToken('CI deploy');

        $this->actingAs($user)
            ->from('/settings/api-tokens')
            ->post('/settings/api-tokens', ['name' => 'CI deploy'])
            ->assertSessionHasErrors('name');
    }

    public function test_user_cannot_revoke_another_users_token(): void
    {
        $self = User::factory()->create();
        $other = User::factory()->create();
        $theirToken = $other->createToken('victim');

        $this->actingAs($self)
            ->from('/settings/api-tokens')
            ->delete('/settings/api-tokens/'.$theirToken->accessToken->id);

        $this->assertSame(
            1,
            $other->tokens()->whereKey($theirToken->accessToken->id)->count(),
            'Other user\'s token must still exist — controller leaked write access',
        );
    }
}
