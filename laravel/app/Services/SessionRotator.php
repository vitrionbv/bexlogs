<?php

namespace App\Services;

use App\Models\BexSession;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Picks a BexSession for a (user, environment) pair using strict
 * round-robin across all currently-usable sessions.
 *
 * Why this exists: with the multi-session model (B6), a single
 * (user, env) tuple can have N sessions tagged HEALTHY/EXPIRING_SOON,
 * each from a different paired browser. The scheduler runs once a
 * minute (sometimes back-to-back when `withoutOverlapping` releases),
 * and the previous session-picker (`User::activeBexSession`) always
 * returned the lowest-priority row — so 100% of jobs landed on the
 * same session and a single cookie expiry took out every queued
 * scrape until the operator re-paired.
 *
 * Round-robin distributes ticks evenly across sessions so:
 *   - per-session load (and per-cookie TTL burn) is balanced;
 *   - one session going stale removes only its share of jobs from
 *     the rotation, the rest keep flowing without operator action;
 *   - new sessions added later automatically get traffic.
 *
 * The counter is stored in a shared cache (Redis in production, the
 * `array` driver in tests) so concurrent scheduler ticks across
 * multiple PHP workers don't both see counter=0 and end up clobbering
 * each other onto the first session. The increment is atomic
 * (`Cache::increment`); the modulo-N picker uses the post-increment
 * value so two ticks racing each other deterministically pick
 * different rows.
 *
 * Falls back to {@see User::activeBexSession} semantics when:
 *   - the user has zero usable sessions for the env (returns null);
 *   - or only one usable session (no rotation needed — fast path).
 */
class SessionRotator
{
    private CacheRepository $cache;

    public function __construct(?CacheRepository $cache = null)
    {
        $this->cache = $cache ?? Cache::store();
    }

    /**
     * Pick the next BexSession in the rotation. Returns null when no
     * usable session exists.
     *
     * The (user_id, environment) pair is hashed into a cache key so
     * each operator-environment combination has its own counter and
     * one slow operator can't starve another.
     */
    public function pickSession(User $user, string $environment): ?BexSession
    {
        $sessions = $user->activeBexSessions($environment);
        $count = $sessions->count();

        if ($count === 0) {
            return null;
        }

        if ($count === 1) {
            // Skip the cache hop — there's nothing to rotate. Also avoids
            // an unbounded counter on single-session users.
            return $sessions->first();
        }

        $key = $this->cacheKey($user->id, $environment);

        // Increment-then-modulo so two scheduler workers racing get
        // distinct indices: `cache->increment` is atomic on Redis and
        // guaranteed-monotonic on the array driver (single process).
        // Initial `add(0)` seeds the counter at 0 the first time so
        // `increment` lands on 1; the picker then maps `1 % N` to the
        // second session (index 0), distributing the very first two
        // ticks across sessions[0] and sessions[1] instead of both
        // landing on [0]. Indexed at (tick-1) so the very first tick
        // (post-increment value 1) maps to sessions[0]; matches what
        // an operator reading "ticks: 1, 2, 3" would expect.
        $this->cache->add($key, 0, now()->addDay());
        $tick = (int) $this->cache->increment($key);

        $idx = ($tick - 1) % $count;

        return $sessions[$idx];
    }

    /**
     * Used by the multi-session UI to render "session X has handled Y%
     * of recent ticks" — purely informational, never read by the
     * scheduler. Returns 0 when the counter has never incremented.
     */
    public function tickCount(int $userId, string $environment): int
    {
        return (int) $this->cache->get($this->cacheKey($userId, $environment), 0);
    }

    /**
     * Reset the counter — exposed for tests so an assertion on the
     * Nth tick doesn't carry state from the previous test.
     */
    public function reset(int $userId, string $environment): void
    {
        $this->cache->forget($this->cacheKey($userId, $environment));
    }

    private function cacheKey(int $userId, string $environment): string
    {
        return "bex:session-rotation:{$userId}:{$environment}";
    }
}
