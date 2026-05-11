<?php

namespace App\Services\AlertDelivery;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Guards against alert spam by suppressing duplicate deliveries to the
 * same channel within a configurable window.
 *
 * The dedupe key is a stable hash of the (saved_query_id, channel_id,
 * row fingerprint) tuple — same row hitting the same channel twice
 * inside the window collapses to one delivery. Different rows with
 * different fingerprints (different status code, different action)
 * generate distinct keys and each fire once.
 *
 * Backed by the cache: in production that's Redis (TTL'd auto-clean);
 * in tests it's the array driver. Either way, the TTL doubles as the
 * dedupe window so we don't have to manage expiration manually.
 *
 * Returns true on the FIRST attempt (no entry yet → reserve the slot
 * → caller proceeds with delivery), false on subsequent attempts
 * within the window (entry exists → caller skips). The reserve-then-
 * proceed semantics avoid a TOCTOU window between the check and the
 * write where two parallel listener invocations could both see "no
 * entry" and both deliver.
 */
class DedupeWindow
{
    private CacheRepository $cache;

    public function __construct(?CacheRepository $cache = null)
    {
        $this->cache = $cache ?? Cache::store();
    }

    /**
     * Try to reserve a delivery slot. Returns true if the caller is
     * allowed to deliver; false if the same key was already reserved
     * inside the window.
     *
     * Window of 0 means "no dedupe" — operators who explicitly want
     * one alert per row (e.g. PagerDuty fanout) configure it via the
     * pivot's `dedupe_window_seconds` column.
     */
    public function reserve(string $key, int $windowSeconds): bool
    {
        if ($windowSeconds <= 0) {
            return true;
        }

        // `add` is atomic across cache backends: writes only when the
        // key doesn't already exist, returning false otherwise. That
        // closes the TOCTOU window described in the class doc.
        return $this->cache->add(
            key: 'alerts:dedupe:'.$key,
            value: 1,
            ttl: $windowSeconds,
        );
    }

    /**
     * Build a stable dedupe key from the query/channel pair plus a
     * fingerprint of the alert subject. The fingerprint deliberately
     * omits the timestamp + the random-feeling fields (request /
     * response payloads, parameters) so two semantically-identical
     * rows (same path + status + action) collapse to one delivery
     * inside the window.
     *
     * @param  array<string,mixed>  $fingerprint
     */
    public static function buildKey(int $queryId, int $channelId, array $fingerprint): string
    {
        ksort($fingerprint);
        $blob = json_encode($fingerprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "{$queryId}:{$channelId}:".sha1((string) $blob);
    }
}
