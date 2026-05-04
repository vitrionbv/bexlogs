<?php

namespace Tests\Unit;

use App\Models\BexSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Unit-test the TTL classifier in BexSession::cookieTtlSummary().
 *
 * The original Authenticate-page heuristic walked the entire cookie
 * jar, took `min(expirationDate)`, and labelled "cookies expired"
 * whenever any cookie was past — which produced the false-positive
 * "Active + cookies expired" contradiction operators reported.
 *
 * The new contract:
 *   - "Session cookie" — auth cookie present but no Expires (browser-
 *     lifetime). MUST NOT label "expired".
 *   - "Expires in Xd Yh" — auth cookie has a future Expires.
 *   - "Expired Xh ago" — auth cookie's Expires is in the past.
 *   - "Unknown" — no auth cookies in the jar at all.
 *
 * These tests pin those four cases plus the headline regression: a
 * jar dominated by short-lived chaff but still carrying a long-lived
 * auth cookie must report the auth cookie's TTL, not the chaff's.
 */
class BexSessionCookieTtlTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = CarbonImmutable::create(2026, 5, 4, 17, 0, 0, 'UTC');
    }

    public function test_session_only_auth_cookies_get_session_cookie_label(): void
    {
        $session = $this->makeSession([
            ['name' => '_BookingExperts_session', 'value' => 'abc'],
            ['name' => 'csrf_token', 'value' => 'xyz', 'expirationDate' => $this->now->copy()->addHour()->getTimestamp()],
        ]);

        $ttl = $session->cookieTtlSummary($this->now);

        $this->assertSame('session', $ttl['kind']);
        $this->assertSame('Session cookie', $ttl['label']);
        $this->assertNull($ttl['expires_at']);
        $this->assertSame('warning', $ttl['tone']);
    }

    public function test_long_lived_auth_cookie_outweighs_short_lived_chaff(): void
    {
        $session = $this->makeSession([
            // 30-day Devise-style auth cookie
            ['name' => 'remember_user_token', 'value' => 'rmb', 'expirationDate' => $this->now->copy()->addDays(30)->getTimestamp()],
            // 1-hour CSRF chaff already expired 30 minutes ago
            ['name' => 'csrf_token', 'value' => 'xyz', 'expirationDate' => $this->now->copy()->subMinutes(30)->getTimestamp()],
            // Locale cookie expiring tomorrow — also chaff
            ['name' => '_locale', 'value' => 'nl', 'expirationDate' => $this->now->copy()->addHours(24)->getTimestamp()],
        ]);

        $ttl = $session->cookieTtlSummary($this->now);

        $this->assertSame('absolute', $ttl['kind']);
        $this->assertSame('success', $ttl['tone'], 'A 30-day auth TTL should land in the success bucket (>7d).');
        $this->assertStringContainsString('Expires in 30d', $ttl['label']);
        $this->assertNotNull($ttl['expires_at']);
    }

    public function test_only_short_lived_auth_cookie_lands_in_warning_tone(): void
    {
        $session = $this->makeSession([
            ['name' => '_app_session', 'value' => 'abc', 'expirationDate' => $this->now->copy()->addHours(6)->getTimestamp()],
        ]);

        $ttl = $session->cookieTtlSummary($this->now);

        $this->assertSame('absolute', $ttl['kind']);
        $this->assertSame('warning', $ttl['tone'], 'A sub-7-day auth TTL should warn but not panic.');
        $this->assertStringContainsString('Expires in 6h', $ttl['label']);
    }

    public function test_expired_auth_cookie_returns_expired_label(): void
    {
        $session = $this->makeSession([
            ['name' => '_BookingExperts_session', 'value' => 'abc', 'expirationDate' => $this->now->copy()->subHours(2)->getTimestamp()],
        ]);

        $ttl = $session->cookieTtlSummary($this->now);

        $this->assertSame('expired', $ttl['kind']);
        $this->assertSame('destructive', $ttl['tone']);
        $this->assertStringContainsString('Expired 2h', $ttl['label']);
        $this->assertStringContainsString('ago', $ttl['label']);
    }

    public function test_no_auth_cookies_returns_unknown(): void
    {
        $session = $this->makeSession([
            ['name' => 'csrf_token', 'value' => 'xyz', 'expirationDate' => $this->now->copy()->addHour()->getTimestamp()],
            ['name' => '_locale', 'value' => 'nl'],
        ]);

        $ttl = $session->cookieTtlSummary($this->now);

        $this->assertSame('unknown', $ttl['kind']);
        $this->assertSame('Unknown', $ttl['label']);
        $this->assertSame('outline', $ttl['tone']);
    }

    public function test_empty_cookie_jar_returns_unknown(): void
    {
        $session = $this->makeSession([]);
        $ttl = $session->cookieTtlSummary($this->now);

        $this->assertSame('unknown', $ttl['kind']);
        $this->assertSame('Unknown', $ttl['label']);
    }

    public function test_session_only_when_auth_cookie_present_but_no_expiration_field(): void
    {
        // Simulates a captured cookie where the extension stored
        // expirationDate=null (Chrome's API returns undefined for
        // session cookies; toExtensionCookie nulls it).
        $session = $this->makeSession([
            ['name' => '_BookingExperts_session', 'value' => 'abc', 'expirationDate' => null],
        ]);

        $ttl = $session->cookieTtlSummary($this->now);

        $this->assertSame('session', $ttl['kind'], 'Null expirationDate must read as session cookie, not expired.');
        $this->assertNotSame('expired', $ttl['kind']);
    }

    /**
     * Mixed jar: auth cookie is session-only, but chaff cookies have
     * already expired. The previous heuristic surfaced the chaff's
     * past Expires and labelled the row "cookies expired" — this is
     * the exact case the operator hit.
     */
    public function test_session_only_auth_with_already_expired_chaff_does_not_label_expired(): void
    {
        $session = $this->makeSession([
            ['name' => '_BookingExperts_session', 'value' => 'abc'],
            ['name' => 'csrf_token', 'value' => 'xyz', 'expirationDate' => $this->now->copy()->subHours(3)->getTimestamp()],
            ['name' => 'ab_bucket', 'value' => 'b', 'expirationDate' => $this->now->copy()->subHours(1)->getTimestamp()],
        ]);

        $ttl = $session->cookieTtlSummary($this->now);

        $this->assertNotSame('expired', $ttl['kind'], 'Chaff expiry must not poison the auth cookie label.');
        $this->assertSame('session', $ttl['kind']);
        $this->assertSame('Session cookie', $ttl['label']);
    }

    /**
     * Build a non-persisted BexSession with the given cookies. We
     * bypass the model's setter (which encrypts) by writing
     * cookies_encrypted directly — the test avoids RefreshDatabase
     * entirely so it stays a true unit test.
     *
     * @param  list<array<string, mixed>>  $cookies
     */
    private function makeSession(array $cookies): BexSession
    {
        $session = new BexSession;
        $session->cookies_encrypted = Crypt::encryptString(json_encode($cookies));

        return $session;
    }
}
