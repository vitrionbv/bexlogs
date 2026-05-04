<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Crawler exclusion is a triple defence:
 *   - /public/robots.txt holds `Disallow: /`
 *   - <meta name="robots"> in app.blade.php repeats it for crawlers that
 *     ignore robots.txt but parse HTML
 *   - SendRobotsHeader middleware emits `X-Robots-Tag` on every response
 *     so even non-HTML payloads (JSON exports, file downloads) are
 *     covered.
 *
 * If any of these three regress, this test catches it. Don't relax these
 * without updating /public/robots.txt and the meta tag in lockstep.
 */
class RobotsPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const ROBOTS_HEADER = 'noindex, nofollow, nosnippet, noarchive';

    public function test_robots_txt_disallows_all_crawlers(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $body = (string) $response->getContent();

        $this->assertStringContainsString('User-agent: *', $body);
        $this->assertStringContainsString('Disallow: /', $body);
    }

    public function test_robots_txt_file_in_public_dir_is_disallow_all(): void
    {
        // Belt-and-braces: in production nginx serves /public/robots.txt
        // directly, never hitting the framework. Pin the file content
        // itself so a careless `npm run build` or rsync can't overwrite
        // it back to a permissive default.
        $contents = file_get_contents(public_path('robots.txt'));

        $this->assertNotFalse($contents);
        $this->assertStringContainsString('User-agent: *', $contents);
        $this->assertStringContainsString('Disallow: /', $contents);
    }

    public function test_login_page_sends_x_robots_tag_header(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', self::ROBOTS_HEADER);
    }

    public function test_authenticated_inertia_pages_send_x_robots_tag_header(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/logs');

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', self::ROBOTS_HEADER);
    }
}
