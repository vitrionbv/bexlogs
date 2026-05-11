<?php

namespace Tests\Feature;

use App\Models\BexSession;
use App\Models\User;
use App\Services\SessionRotator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Covers the B6 round-robin session picker.
 *
 * The contract:
 *   - 9 sequential calls across 3 healthy sessions distribute
 *     EXACTLY 3 picks per session (perfect round-robin, no drift).
 *   - A single healthy session always returns itself (fast path,
 *     no cache hop).
 *   - Zero usable sessions returns null without raising.
 *
 * The cache backend in tests is the array driver (see phpunit.xml's
 * `CACHE_STORE=array`), so the increment counter is in-process and
 * deterministic across the loop.
 */
class SessionRotatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_distributes_evenly_across_three_sessions_over_nine_ticks(): void
    {
        $user = User::factory()->create();

        // Three healthy sessions, all with the default priority of
        // 100 and ordered by id (the rotator uses
        // `User::activeBexSessions` which sorts by priority, id).
        // Cookies expire 30 days out so the saving hook tags every
        // row `healthy`.
        $expires = Carbon::now()->addDays(30)->getTimestamp();
        $sessions = [];
        for ($i = 0; $i < 3; $i++) {
            $sessions[] = BexSession::create([
                'user_id' => $user->id,
                'environment' => 'production',
                'cookies_encrypted' => Crypt::encryptString(json_encode([
                    ['name' => '_bex_session', 'value' => "cookie-{$i}", 'expirationDate' => $expires],
                ])),
                'captured_at' => now(),
            ]);
        }

        $rotator = new SessionRotator;
        $rotator->reset($user->id, 'production');

        $picks = [];
        for ($tick = 0; $tick < 9; $tick++) {
            $session = $rotator->pickSession($user, 'production');
            $this->assertNotNull($session);
            $picks[] = $session->id;
        }

        $this->assertCount(9, $picks);

        // 9 ticks ÷ 3 sessions = 3 picks per session. Strict
        // round-robin so the distribution is exactly 3/3/3, not
        // approximately balanced.
        $counts = array_count_values($picks);
        foreach ($sessions as $s) {
            $this->assertSame(
                3,
                $counts[$s->id] ?? 0,
                "Session #{$s->id} should be picked exactly 3 times in 9 ticks; got ".($counts[$s->id] ?? 0),
            );
        }

        // Order matters too — the sequence is round-robin
        // [0,1,2,0,1,2,0,1,2], not a balanced-but-shuffled set.
        // Pin the first 3 picks to confirm the picker isn't
        // randomising.
        $this->assertSame($sessions[0]->id, $picks[0]);
        $this->assertSame($sessions[1]->id, $picks[1]);
        $this->assertSame($sessions[2]->id, $picks[2]);
        $this->assertSame($sessions[0]->id, $picks[3]);
    }

    public function test_single_session_skips_cache_and_returns_itself(): void
    {
        $user = User::factory()->create();

        $expires = Carbon::now()->addDays(30)->getTimestamp();
        $session = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => Crypt::encryptString(json_encode([
                ['name' => '_bex_session', 'value' => 'cookie', 'expirationDate' => $expires],
            ])),
            'captured_at' => now(),
        ]);

        $rotator = new SessionRotator;
        $rotator->reset($user->id, 'production');

        $picked = $rotator->pickSession($user, 'production');
        $this->assertNotNull($picked);
        $this->assertSame($session->id, $picked->id);

        // Counter never advances on the single-session fast path —
        // unbounded counters on single-session users would be
        // pointless cache writes.
        $this->assertSame(0, $rotator->tickCount($user->id, 'production'));
    }

    public function test_zero_usable_sessions_returns_null(): void
    {
        $user = User::factory()->create();

        // Create one session and immediately disable it. Disabled
        // rows are never returned by activeBexSessions().
        $session = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => Crypt::encryptString(json_encode([
                ['name' => '_bex_session', 'value' => 'cookie'],
            ])),
            'captured_at' => now(),
        ]);
        $session->forceFill(['health_status' => BexSession::HEALTH_DISABLED])->save();

        $rotator = new SessionRotator;
        $this->assertNull($rotator->pickSession($user, 'production'));
    }

    public function test_only_failing_session_disables_itself_and_keeps_peers_in_rotation(): void
    {
        // Sanity check the B6 invariant from WorkerController::fail —
        // marking ONE session expired must not perturb the rotation
        // across the other healthy sessions.
        $user = User::factory()->create();

        $expires = Carbon::now()->addDays(30)->getTimestamp();
        $bad = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => Crypt::encryptString(json_encode([
                ['name' => '_bex_session', 'value' => 'cookie-bad', 'expirationDate' => $expires],
            ])),
            'captured_at' => now(),
        ]);
        $good1 = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => Crypt::encryptString(json_encode([
                ['name' => '_bex_session', 'value' => 'cookie-1', 'expirationDate' => $expires],
            ])),
            'captured_at' => now(),
        ]);
        $good2 = BexSession::create([
            'user_id' => $user->id,
            'environment' => 'production',
            'cookies_encrypted' => Crypt::encryptString(json_encode([
                ['name' => '_bex_session', 'value' => 'cookie-2', 'expirationDate' => $expires],
            ])),
            'captured_at' => now(),
        ]);

        // Simulate WorkerController::fail's branch for `session_expired`
        // (raw query: matches the production code path verbatim).
        BexSession::query()
            ->whereKey($bad->id)
            ->update([
                'expired_at' => now(),
                'health_status' => BexSession::HEALTH_EXPIRED,
            ]);

        $remaining = $user->fresh()->activeBexSessions('production')->pluck('id')->all();

        $this->assertSame([$good1->id, $good2->id], $remaining);

        $rotator = new SessionRotator;
        $rotator->reset($user->id, 'production');

        $picks = [];
        for ($tick = 0; $tick < 4; $tick++) {
            $session = $rotator->pickSession($user->fresh(), 'production');
            $this->assertNotNull($session);
            $picks[] = $session->id;
        }

        $this->assertSame([$good1->id, $good2->id, $good1->id, $good2->id], $picks);
    }
}
