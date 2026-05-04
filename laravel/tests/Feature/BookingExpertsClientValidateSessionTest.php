<?php

namespace Tests\Feature;

use App\Models\BexSession;
use App\Models\User;
use App\Services\BookingExpertsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BookingExpertsClient::validateSession must walk the redirect chain off
 * GET / before declaring a verdict. Production stale-cookie failures
 * traced to the old implementation, which only inspected the FIRST
 * redirect's Location header: BookingExperts redirects an unauthenticated
 * GET / to a neutral /redirect?locale=nl first, only emitting
 * /users/sign_in on the second hop. The old validator saw a clean first
 * redirect and marked the session "valid", which made every subsequent
 * scraper job classify as session_expired against a session the UI
 * still labelled "linked" — exactly the discrepancy the operator was
 * chasing in the previous diagnosis (commit 4c094b1 / deploy/README.md
 * "Diagnosing Session expired failures").
 *
 * These tests pin the corrected behaviour:
 *   - chain is followed (bounded at MAX_REDIRECT_HOPS = 5),
 *   - any /sign_in / /users/sign_in / /users/login marker anywhere in
 *     the chain flips to invalid,
 *   - the verdict carries a machine-readable `reason` so the refresher
 *     and operators can disambiguate expired vs. network vs. unknown
 *     vs. redirect_loop without re-parsing log lines.
 */
class BookingExpertsClientValidateSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_session_redirect_chain_to_dashboard_marks_valid(): void
    {
        $requestedUrls = [];

        Http::fake(function (Request $req) use (&$requestedUrls) {
            $requestedUrls[] = $req->url();

            return match (true) {
                $req->url() === 'https://app.bookingexperts.com/' => Http::response('', 301, [
                    'Location' => 'https://app.bookingexperts.com/redirect?locale=nl',
                ]),
                $req->url() === 'https://app.bookingexperts.com/redirect?locale=nl' => Http::response('', 302, [
                    'Location' => 'https://app.bookingexperts.com/parks/2339/dashboard',
                ]),
                $req->url() === 'https://app.bookingexperts.com/parks/2339/dashboard' => Http::response(
                    $this->dashboardHtml('sherin@verbleif.com', 'Sherin Bloemendaal'),
                    200,
                ),
                default => Http::response('unexpected url: '.$req->url(), 500),
            };
        });

        $result = (new BookingExpertsClient)->validateSession($this->makeSession());

        $this->assertTrue($result['valid'], 'A chain landing on a 200 dashboard must be valid.');
        $this->assertSame('ok', $result['reason']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('sherin@verbleif.com', $result['email']);
        $this->assertSame('Sherin Bloemendaal', $result['name']);
        $this->assertCount(3, $result['chain'], 'Three hops: /, /redirect, /dashboard.');

        $this->assertSame([
            'https://app.bookingexperts.com/',
            'https://app.bookingexperts.com/redirect?locale=nl',
            'https://app.bookingexperts.com/parks/2339/dashboard',
        ], $requestedUrls);
    }

    public function test_stale_session_redirect_chain_to_sign_in_marks_expired(): void
    {
        $requestedUrls = [];

        Http::fake(function (Request $req) use (&$requestedUrls) {
            $requestedUrls[] = $req->url();

            return match (true) {
                $req->url() === 'https://app.bookingexperts.com/' => Http::response('', 301, [
                    'Location' => 'https://app.bookingexperts.com/redirect?locale=nl',
                ]),
                $req->url() === 'https://app.bookingexperts.com/redirect?locale=nl' => Http::response('', 302, [
                    'Location' => 'https://app.bookingexperts.com/users/sign_in',
                ]),
                default => Http::response('unexpected url: '.$req->url(), 500),
            };
        });

        $result = (new BookingExpertsClient)->validateSession($this->makeSession());

        $this->assertFalse(
            $result['valid'],
            'The exact production false-positive: stale chain ends at /users/sign_in via a neutral first hop and must be invalid.',
        );
        $this->assertSame('expired', $result['reason']);
        $this->assertNull($result['email']);
        $this->assertNull($result['name']);
        $this->assertSame('https://app.bookingexperts.com/users/sign_in', $result['redirect']);
        $this->assertCount(2, $result['chain']);

        // Validator must short-circuit on the sign-in Location — it should
        // NOT actually follow INTO /users/sign_in (no wasted round trips).
        $this->assertSame([
            'https://app.bookingexperts.com/',
            'https://app.bookingexperts.com/redirect?locale=nl',
        ], $requestedUrls);
    }

    public function test_stale_session_direct_redirect_to_sign_in_marks_expired(): void
    {
        $requestedUrls = [];

        Http::fake(function (Request $req) use (&$requestedUrls) {
            $requestedUrls[] = $req->url();

            return Http::response('', 302, [
                'Location' => 'https://app.bookingexperts.com/users/sign_in',
            ]);
        });

        $result = (new BookingExpertsClient)->validateSession($this->makeSession());

        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['reason']);
        $this->assertSame(302, $result['status']);
        $this->assertSame(['https://app.bookingexperts.com/'], $requestedUrls);
    }

    public function test_dashboard_directly_at_root_marks_valid(): void
    {
        Http::fake([
            '*' => Http::response(
                $this->dashboardHtml('sherin@verbleif.com', 'Sherin Bloemendaal'),
                200,
            ),
        ]);

        $result = (new BookingExpertsClient)->validateSession($this->makeSession());

        $this->assertTrue($result['valid']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame(200, $result['status']);
        $this->assertSame('sherin@verbleif.com', $result['email']);
        $this->assertSame('Sherin Bloemendaal', $result['name']);
        $this->assertCount(1, $result['chain']);
    }

    public function test_redirect_bomb_exceeding_hop_cap_marks_redirect_loop_without_hanging(): void
    {
        $hops = 0;

        Http::fake(function () use (&$hops) {
            $hops++;

            return Http::response('', 302, [
                'Location' => 'https://app.bookingexperts.com/loop'.$hops,
            ]);
        });

        $result = (new BookingExpertsClient)->validateSession($this->makeSession());

        $this->assertFalse($result['valid']);
        $this->assertSame(
            'redirect_loop',
            $result['reason'],
            'A chain longer than MAX_REDIRECT_HOPS must give up rather than hang or count as expired.',
        );
        // Cap = 5 redirects followed → 1 initial GET + 5 follows = 6 hops total.
        $this->assertSame(6, $hops, 'Validator must stop after MAX_REDIRECT_HOPS+1 requests.');
        $this->assertCount(6, $result['chain']);
    }

    public function test_terminal_5xx_response_marks_unknown_not_expired(): void
    {
        Http::fake([
            '*' => Http::response('Internal Server Error', 500),
        ]);

        $result = (new BookingExpertsClient)->validateSession($this->makeSession());

        $this->assertFalse($result['valid']);
        $this->assertSame(
            'unknown',
            $result['reason'],
            'Maintenance / 5xx responses are a soft failure — not a definite expiry.',
        );
        $this->assertSame(500, $result['status']);
        $this->assertNull($result['email']);
    }

    public function test_connection_exception_marks_network_not_expired(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to app.bookingexperts.com');
        });

        $result = (new BookingExpertsClient)->validateSession($this->makeSession());

        $this->assertFalse($result['valid']);
        $this->assertSame(
            'network',
            $result['reason'],
            'Network errors must NOT be conflated with stale sessions — the cookies might be perfectly valid and we just couldn\'t reach BE.',
        );
        $this->assertSame(0, $result['status']);
        $this->assertNull($result['email']);
    }

    public function test_non_sign_in_3xx_chain_to_200_with_root_relative_locations_marks_valid(): void
    {
        // Verifies the resolveRedirect helper handles root-relative
        // Location headers (which is what BE emits in practice for the
        // /redirect → /parks/* hop). If this test fails, every fresh
        // session would also be misclassified.
        $requestedUrls = [];

        Http::fake(function (Request $req) use (&$requestedUrls) {
            $requestedUrls[] = $req->url();

            return match (true) {
                $req->url() === 'https://app.bookingexperts.com/' => Http::response('', 301, [
                    'Location' => '/redirect?locale=nl',
                ]),
                $req->url() === 'https://app.bookingexperts.com/redirect?locale=nl' => Http::response('', 302, [
                    'Location' => '/parks/2339/dashboard',
                ]),
                $req->url() === 'https://app.bookingexperts.com/parks/2339/dashboard' => Http::response(
                    $this->dashboardHtml('sherin@verbleif.com', 'Sherin Bloemendaal'),
                    200,
                ),
                default => Http::response('unexpected url: '.$req->url(), 500),
            };
        });

        $result = (new BookingExpertsClient)->validateSession($this->makeSession());

        $this->assertTrue($result['valid']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame([
            'https://app.bookingexperts.com/',
            'https://app.bookingexperts.com/redirect?locale=nl',
            'https://app.bookingexperts.com/parks/2339/dashboard',
        ], $requestedUrls);
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

    /**
     * Minimal dashboard HTML the extractUserIdentity heuristics can pull
     * an email + name out of. Mirrors the shape used by
     * BexSessionRelinkTest::bookingExpertsHomeHtml.
     */
    private function dashboardHtml(string $email, string $name): string
    {
        return <<<HTML
<!doctype html>
<html>
<head><title>BEX PMS</title></head>
<body>
<div class="user-menu">{$name}</div>
<a href="mailto:{$email}">{$email}</a>
</body>
</html>
HTML;
    }
}
