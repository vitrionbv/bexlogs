<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/` has no public surface. Guests are bounced to the login screen,
 * authenticated users land on /logs (the primary work surface). The
 * old marketing Welcome page was removed; if you bring back any kind
 * of public root page, update this test in lockstep with Fortify's
 * `home` config (config/fortify.php) which post-login redirects use.
 */
class HomepageRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_root_to_login(): void
    {
        $this->get('/')
            ->assertStatus(302)
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_are_redirected_from_root_to_logs(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertStatus(302)
            ->assertRedirect('/logs');
    }
}
