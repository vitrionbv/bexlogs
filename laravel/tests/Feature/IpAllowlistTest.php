<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance tests for the IP allowlist middleware.
 *
 * The single most important invariant here is **empty allowlist == open**.
 * The middleware's whole point is to lock the app down to a small set of
 * IPs once the operator has explicitly opted in via APP_IP_ALLOWLIST. A
 * fresh deploy without that env var set must continue to behave exactly
 * as before, otherwise we'd brick ourselves on every initial bootstrap.
 * Test A pins that down. Don't delete it.
 */
class IpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_allowlist_passes_any_ip(): void
    {
        config(['app.ip_allowlist' => []]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->get('/')
            ->assertOk();
    }

    public function test_allowed_literal_ip_passes(): void
    {
        config(['app.ip_allowlist' => ['93.119.3.188']]);

        $this->withServerVariables(['REMOTE_ADDR' => '93.119.3.188'])
            ->get('/')
            ->assertOk();
    }

    public function test_disallowed_ip_is_rejected(): void
    {
        config(['app.ip_allowlist' => ['93.119.3.188']]);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->get('/');

        $response->assertForbidden();
        // The 403 body must not echo the allowlist — that would tell an
        // attacker exactly which IPs they need to spoof.
        $this->assertStringNotContainsString('93.119.3.188', (string) $response->getContent());
    }

    public function test_cidr_allowlist_matches_address_inside_range(): void
    {
        config(['app.ip_allowlist' => ['10.0.0.0/24']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.42'])
            ->get('/')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->get('/')
            ->assertForbidden();
    }

    public function test_up_route_is_never_blocked(): void
    {
        // Even with a strict allowlist that *would* reject this IP, the
        // healthcheck must keep returning 200 — Caddy and the deploy
        // workflow probe `/up` from inside the docker network and we
        // can't afford to fail those.
        config(['app.ip_allowlist' => ['93.119.3.188']]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->get('/up')
            ->assertOk();
    }

    public function test_cf_connecting_ip_takes_precedence_over_request_ip(): void
    {
        // In production Caddy doesn't trust Cloudflare's edge as a proxy
        // so X-Forwarded-For arrives at Laravel as a CF edge IP — not the
        // real visitor. The middleware MUST consult CF-Connecting-IP, or
        // the user (and only the user) gets locked out the second the
        // allowlist is turned on.
        config(['app.ip_allowlist' => ['93.119.3.188']]);

        $this->withServerVariables(['REMOTE_ADDR' => '104.23.166.126'])
            ->withHeaders(['CF-Connecting-IP' => '93.119.3.188'])
            ->get('/')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '104.23.166.126'])
            ->withHeaders(['CF-Connecting-IP' => '1.2.3.4'])
            ->get('/')
            ->assertForbidden();
    }

    public function test_worker_api_is_never_ip_restricted(): void
    {
        config(['app.ip_allowlist' => ['93.119.3.188']]);

        // The scraper hits this from inside the docker network and is
        // auth-gated by WORKER_API_TOKEN. Without a Bearer token the
        // route itself returns 401 (or 503 if the token isn't set in
        // the test env) — anything *but* 403 proves the IP filter
        // didn't stomp on it.
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/api/worker/jobs/next');

        $this->assertNotSame(
            403,
            $response->status(),
            'IP allowlist must not gate /api/worker/* — that would break the scraper.',
        );
    }
}
