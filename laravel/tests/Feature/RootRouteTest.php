<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The old `/dashboard` post-login landing was removed; `/` now sends
 * authenticated users straight to the Logs index (the primary work
 * surface) and keeps the marketing Welcome page visible to guests.
 *
 * If the redirect target ever needs to change, update Fortify's `home`
 * config in lockstep — Fortify's post-login redirect should land in the
 * same place this route does.
 */
class RootRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_are_redirected_from_root_to_logs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect('/logs');
    }

    public function test_guests_see_the_welcome_page_at_root(): void
    {
        $response = $this->get('/');

        // Preserve pre-existing guest behaviour: guests get the public
        // marketing page (200), they are NOT bounced to /login. The
        // login link is rendered inside Welcome itself.
        $response->assertOk();
    }
}
