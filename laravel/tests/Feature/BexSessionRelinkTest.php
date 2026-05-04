<?php

namespace Tests\Feature;

use App\Events\BexSessionRelinked;
use App\Models\BexSession;
use App\Models\PairingToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Exercise the extension pairing endpoint's update-vs-create decision.
 *
 * When the extension POSTs cookies with a valid pairing token, the
 * server probes BookingExperts once to resolve the signed-in user's
 * email + name BEFORE touching the database. That probe drives the
 * fork:
 *
 *   - Matching (user_id, environment, account_email) → UPDATE that
 *     row in place, clear expired_at, bump last_validated_at, and
 *     broadcast BexSessionRelinked. Zero new rows.
 *
 *   - No match (different account, or no extractable email) → INSERT
 *     a new row. The original row is untouched.
 *
 * Tests pin both branches + the invalid-token guard so future refactors
 * can't accidentally regress to "always create" (which is what the user
 * complained about: re-auth produced a new session row every time).
 */
class BexSessionRelinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_reauth_with_matching_account_email_updates_existing_row(): void
    {
        Event::fake([BexSessionRelinked::class]);

        $html = $this->bookingExpertsHomeHtml('sherin@verbleif.com', 'Sherin Bloemendaal');
        Http::fake([
            'https://app.bookingexperts.com/*' => Http::response($html, 200),
            'https://app.bookingexperts.com' => Http::response($html, 200),
        ]);

        $user = User::factory()->create();

        $existing = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_bex_session', 'value' => 'old-cookie'],
            ])),
            'account_email' => 'sherin@verbleif.com',
            'account_name' => 'Sherin Bloemendaal',
            'captured_at' => now()->subDays(3),
            'expired_at' => now()->subHour(),
        ]);

        $token = PairingToken::generate(
            userId: $user->id,
            environment: 'production',
            ttlMinutes: 5,
        );

        $response = $this->postJson('/api/bex-sessions', [
            'token' => $token->token,
            'cookies' => [[
                'name' => '_bex_session',
                'value' => 'freshly-captured',
                'domain' => '.app.bookingexperts.com',
                'path' => '/',
                'httpOnly' => true,
                'secure' => true,
                'sameSite' => 'lax',
            ]],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $existing->id,
            'relinked' => true,
            'is_active' => true,
        ]);

        $this->assertSame(
            1,
            BexSession::query()->where('user_id', $user->id)->count(),
            'No new session row should be inserted on a matching-account relink.',
        );

        $existing->refresh();
        $this->assertNull($existing->expired_at, 'Relink should clear expired_at.');
        $this->assertNotNull($existing->last_validated_at);
        $this->assertSame('sherin@verbleif.com', $existing->account_email);
        $this->assertSame(
            'freshly-captured',
            collect($existing->cookies)->firstWhere('name', '_bex_session')['value'] ?? null,
            'Cookies must be updated to the new capture.',
        );

        Event::assertDispatched(
            BexSessionRelinked::class,
            fn (BexSessionRelinked $e) => $e->bexSessionId === $existing->id
                && $e->userId === $user->id
                && $e->environment === 'production'
                && $e->accountEmail === 'sherin@verbleif.com',
        );

        $this->assertTrue(
            $token->fresh()->consumed_at !== null,
            'The pairing token must be marked consumed after a successful relink.',
        );
        $this->assertSame($existing->id, (int) $token->fresh()->bex_session_id);
    }

    public function test_reauth_with_different_account_email_creates_new_row(): void
    {
        Event::fake([BexSessionRelinked::class]);

        $html = $this->bookingExpertsHomeHtml('other@example.com', 'Other Person');
        Http::fake([
            'https://app.bookingexperts.com/*' => Http::response($html, 200),
            'https://app.bookingexperts.com' => Http::response($html, 200),
        ]);

        $user = User::factory()->create();

        $existing = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_bex_session', 'value' => 'old-account-cookie'],
            ])),
            'account_email' => 'sherin@verbleif.com',
            'account_name' => 'Sherin Bloemendaal',
            'captured_at' => now()->subDays(3),
        ]);
        $originalExistingCookies = $existing->cookies;
        $originalCapturedAt = $existing->captured_at;

        $token = PairingToken::generate(
            userId: $user->id,
            environment: 'production',
            ttlMinutes: 5,
        );

        $response = $this->postJson('/api/bex-sessions', [
            'token' => $token->token,
            'cookies' => [[
                'name' => '_bex_session',
                'value' => 'other-account-cookie',
                'domain' => '.app.bookingexperts.com',
                'path' => '/',
            ]],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'relinked' => false,
            'is_active' => true,
            'account_email' => 'other@example.com',
        ]);

        $this->assertSame(
            2,
            BexSession::query()->where('user_id', $user->id)->count(),
            'A different account_email must produce a second row.',
        );

        $existing->refresh();
        $this->assertSame(
            $originalExistingCookies,
            $existing->cookies,
            'The prior-account row must be untouched when a different account pairs.',
        );
        $this->assertEquals($originalCapturedAt->toIso8601String(), $existing->captured_at->toIso8601String());
        $this->assertSame('sherin@verbleif.com', $existing->account_email);

        $newRow = BexSession::query()
            ->where('user_id', $user->id)
            ->where('id', '!=', $existing->id)
            ->first();
        $this->assertNotNull($newRow);
        $this->assertSame('other@example.com', $newRow->account_email);

        Event::assertNotDispatched(BexSessionRelinked::class);
    }

    public function test_expired_pairing_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $token = PairingToken::generate(
            userId: $user->id,
            environment: 'production',
            ttlMinutes: 5,
        );
        $token->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->postJson('/api/bex-sessions', [
            'token' => $token->token,
            'cookies' => [[
                'name' => '_bex_session',
                'value' => 'whatever',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'invalid_token']);

        $this->assertSame(
            0,
            BexSession::query()->where('user_id', $user->id)->count(),
            'No session row should be created when the token is expired.',
        );
    }

    public function test_validation_failure_falls_back_to_create_new_row(): void
    {
        // When BookingExperts rejects the cookies (redirects to /sign_in)
        // the probe returns email = null. Without an identifying email we
        // can't safely match an existing row, so we INSERT. The new row
        // is stamped with expired_at so the UI flags it as "needs
        // relogging in" immediately.
        Event::fake([BexSessionRelinked::class]);

        Http::fake([
            'https://app.bookingexperts.com/*' => Http::response('', 302, [
                'Location' => 'https://app.bookingexperts.com/sign_in',
            ]),
            'https://app.bookingexperts.com' => Http::response('', 302, [
                'Location' => 'https://app.bookingexperts.com/sign_in',
            ]),
        ]);

        $user = User::factory()->create();

        BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_bex_session', 'value' => 'old-cookie'],
            ])),
            'account_email' => 'sherin@verbleif.com',
            'captured_at' => now()->subDays(3),
        ]);

        $token = PairingToken::generate(
            userId: $user->id,
            environment: 'production',
            ttlMinutes: 5,
        );

        $response = $this->postJson('/api/bex-sessions', [
            'token' => $token->token,
            'cookies' => [[
                'name' => '_bex_session',
                'value' => 'junk-cookie',
            ]],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'relinked' => false,
            'is_active' => false,
        ]);

        $this->assertSame(
            2,
            BexSession::query()->where('user_id', $user->id)->count(),
            'A failed probe (no email extractable) must INSERT, not clobber the existing row.',
        );

        $fresh = BexSession::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($fresh->expired_at);
        Event::assertNotDispatched(BexSessionRelinked::class);
    }

    public function test_relink_adopts_empty_email_orphan_row_when_probe_resolves_email(): void
    {
        // Pre-extractor capture: the row's account_email is empty
        // because BookingExpertsClient couldn't pull an email out of
        // the home page at the time. The new probe DOES resolve an
        // email — we must adopt the orphan row instead of creating a
        // duplicate next to it.
        Event::fake([BexSessionRelinked::class]);

        $html = $this->bookingExpertsHomeHtml('sherin@verbleif.com', 'Sherin Bloemendaal');
        Http::fake([
            'https://app.bookingexperts.com/*' => Http::response($html, 200),
            'https://app.bookingexperts.com' => Http::response($html, 200),
        ]);

        $user = User::factory()->create();

        $orphan = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_bex_session', 'value' => 'pre-extractor'],
            ])),
            'account_email' => '',
            'account_name' => null,
            'captured_at' => now()->subDays(5),
            'expired_at' => now()->subHour(),
        ]);

        $token = PairingToken::generate(
            userId: $user->id,
            environment: 'production',
            ttlMinutes: 5,
        );

        $response = $this->postJson('/api/bex-sessions', [
            'token' => $token->token,
            'cookies' => [[
                'name' => '_bex_session',
                'value' => 'fresh-cookie',
                'domain' => '.app.bookingexperts.com',
            ]],
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $orphan->id,
            'relinked' => true,
            'is_active' => true,
            'account_email' => 'sherin@verbleif.com',
        ]);

        $this->assertSame(
            1,
            BexSession::query()->where('user_id', $user->id)->count(),
            'Empty-email orphan should be adopted, not duplicated.',
        );

        $orphan->refresh();
        $this->assertSame('sherin@verbleif.com', $orphan->account_email, 'Adoption must back-fill the email.');
        $this->assertNull($orphan->expired_at);
        $this->assertNotNull($orphan->last_validated_at);

        Event::assertDispatched(
            BexSessionRelinked::class,
            fn (BexSessionRelinked $e) => $e->bexSessionId === $orphan->id
                && $e->accountEmail === 'sherin@verbleif.com',
        );
    }

    public function test_relink_does_not_adopt_when_multiple_empty_email_rows_exist(): void
    {
        // Guard: with two empty-email candidates we can't be sure
        // which orphan belongs to the email the probe just resolved.
        // Falling back to INSERT keeps the operator in control —
        // they can clean up the duplicates by hand (or via the prune
        // command) later.
        Event::fake([BexSessionRelinked::class]);

        $html = $this->bookingExpertsHomeHtml('sherin@verbleif.com', 'Sherin Bloemendaal');
        Http::fake([
            'https://app.bookingexperts.com/*' => Http::response($html, 200),
            'https://app.bookingexperts.com' => Http::response($html, 200),
        ]);

        $user = User::factory()->create();

        BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_bex_session', 'value' => 'orphan-A'],
            ])),
            'account_email' => '',
            'captured_at' => now()->subDays(7),
            'expired_at' => now()->subDays(2),
        ]);
        BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_bex_session', 'value' => 'orphan-B'],
            ])),
            'account_email' => '',
            'captured_at' => now()->subDays(4),
            'expired_at' => now()->subDay(),
        ]);

        $token = PairingToken::generate(
            userId: $user->id,
            environment: 'production',
            ttlMinutes: 5,
        );

        $response = $this->postJson('/api/bex-sessions', [
            'token' => $token->token,
            'cookies' => [[
                'name' => '_bex_session',
                'value' => 'fresh-cookie',
                'domain' => '.app.bookingexperts.com',
            ]],
        ]);

        $response->assertStatus(201);
        $response->assertJson(['relinked' => false]);
        $this->assertSame(
            3,
            BexSession::query()->where('user_id', $user->id)->count(),
            'With ambiguous empty-email candidates, INSERT a fresh row instead of guessing.',
        );

        Event::assertNotDispatched(BexSessionRelinked::class);
    }

    public function test_relink_does_not_adopt_active_empty_email_row(): void
    {
        // Even the empty-email back-fill should leave a still-active
        // (expired_at IS NULL) row alone — re-binding cookies on a
        // currently-valid session would surprise-overwrite working
        // state. Only expired empty-email rows are eligible.
        Event::fake([BexSessionRelinked::class]);

        $html = $this->bookingExpertsHomeHtml('sherin@verbleif.com', 'Sherin Bloemendaal');
        Http::fake([
            'https://app.bookingexperts.com/*' => Http::response($html, 200),
            'https://app.bookingexperts.com' => Http::response($html, 200),
        ]);

        $user = User::factory()->create();

        BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_bex_session', 'value' => 'still-good'],
            ])),
            'account_email' => '',
            'captured_at' => now()->subDays(2),
            'expired_at' => null,
            'last_validated_at' => now()->subMinutes(5),
        ]);

        $token = PairingToken::generate(
            userId: $user->id,
            environment: 'production',
            ttlMinutes: 5,
        );

        $response = $this->postJson('/api/bex-sessions', [
            'token' => $token->token,
            'cookies' => [[
                'name' => '_bex_session',
                'value' => 'newly-captured',
                'domain' => '.app.bookingexperts.com',
            ]],
        ]);

        $response->assertStatus(201);
        $response->assertJson(['relinked' => false]);
        $this->assertSame(2, BexSession::query()->where('user_id', $user->id)->count());

        Event::assertNotDispatched(BexSessionRelinked::class);
    }

    public function test_authenticate_page_shows_reauthenticate_button_for_expired_sessions(): void
    {
        $user = User::factory()->create();

        BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([
                ['name' => '_bex_session', 'value' => 'stale'],
            ])),
            'account_email' => 'sherin@verbleif.com',
            'account_name' => 'Sherin Bloemendaal',
            'captured_at' => now()->subDays(2),
            'expired_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($user)->get('/authenticate');

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page->component('Authenticate/Index')
                ->where('sessions.0.is_active', false)
                ->where('sessions.0.account_email', 'sherin@verbleif.com'),
        );
        // The "Re-authenticate" copy is rendered client-side for rows
        // with is_active: false. The Inertia payload is what gates the
        // UI so asserting the expired row lands in props is enough;
        // Vue will mount the Re-authenticate button based on it.
    }

    /**
     * Craft a minimal BE home-page HTML that BookingExpertsClient's
     * extractUserIdentity() heuristics can pull an email + name out of.
     * The regex is picky about structure — the name-matching div must
     * contain *only* text (no nested tags between the opening `>` and
     * the first `<`), otherwise the `[^<]{2,80}` capture bails.
     */
    private function bookingExpertsHomeHtml(string $email, string $name): string
    {
        return <<<HTML
<!doctype html>
<html>
<head><title>BookingExperts</title></head>
<body>
<div class="user-menu">{$name}</div>
<a href="mailto:{$email}">{$email}</a>
</body>
</html>
HTML;
    }
}
