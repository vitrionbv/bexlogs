<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict app access to a configured IP allowlist.
 *
 * Reads `config('app.ip_allowlist')` (a list of CIDRs / literal IPs derived
 * from APP_IP_ALLOWLIST). When the list is empty, the middleware is a no-op
 * — fresh deploys never lock themselves out before the operator has set the
 * env var.
 *
 * Loopback (127.0.0.1, ::1) is always allowed implicitly so the container's
 * own `/up` healthcheck can never be blocked. The middleware also short
 * circuits the `/up` route and the worker API (`/api/worker/*`) — the
 * scraper hits the latter from inside the docker network and is already
 * gated by WORKER_API_TOKEN.
 *
 * Rejected requests get a non-leaky 403 and a one-line `notice` log entry
 * with IP, method, URL, and User-Agent. Garbage allowlist entries (e.g.
 * hostnames) are silently skipped — we log a single warning per worker
 * process so the operator notices in the logs without spamming a line per
 * request.
 */
class EnsureClientIpIsAllowed
{
    /**
     * Per-process flag so we only log the "invalid entry" warning once
     * per PHP worker (instead of on every rejected request).
     *
     * @var array<string, true>
     */
    private static array $loggedInvalidEntries = [];

    public function handle(Request $request, Closure $next): Response
    {
        // Container healthcheck (Caddy / Docker probes `/up` from inside
        // the docker network) and the scraper worker API (auth-gated by
        // WORKER_API_TOKEN, internal docker net only) are never IP
        // restricted regardless of allowlist.
        if ($request->is('up') || $request->is('api/worker/*')) {
            return $next($request);
        }

        $allowlist = $this->normaliseAllowlist((array) config('app.ip_allowlist', []));

        // Empty allowlist == open. Crucial: a fresh deploy without
        // APP_IP_ALLOWLIST set must NOT lock the operator out.
        if ($allowlist === []) {
            return $next($request);
        }

        $clientIp = (string) $request->ip();

        // Loopback is always allowed (the in-container healthcheck talks
        // to the app over 127.0.0.1 even after TrustProxies kicks in), no
        // matter what the operator put in the allowlist.
        if ($clientIp === '127.0.0.1' || $clientIp === '::1') {
            return $next($request);
        }

        if (IpUtils::checkIp($clientIp, $allowlist)) {
            return $next($request);
        }

        Log::notice('IP allowlist: rejected request', [
            'ip' => $clientIp,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_agent' => $request->userAgent(),
        ]);

        abort(403, 'Access denied for your IP.');
    }

    /**
     * Drop entries that aren't valid IPs / CIDRs so a fat-finger like
     * `APP_IP_ALLOWLIST=example.com` can't crash the request pipeline.
     * Logs a single warning per process when garbage is encountered so
     * the operator notices in the logs.
     *
     * @param  array<int, mixed>  $allowlist
     * @return array<int, string>
     */
    private function normaliseAllowlist(array $allowlist): array
    {
        $valid = [];

        foreach ($allowlist as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if ($this->looksLikeIpOrCidr($entry)) {
                $valid[] = $entry;

                continue;
            }

            if (! isset(self::$loggedInvalidEntries[$entry])) {
                self::$loggedInvalidEntries[$entry] = true;
                Log::warning('IP allowlist: ignoring invalid entry', ['entry' => $entry]);
            }
        }

        return $valid;
    }

    private function looksLikeIpOrCidr(string $entry): bool
    {
        $ip = $entry;

        if (str_contains($entry, '/')) {
            [$ip, $mask] = explode('/', $entry, 2);

            if (! ctype_digit($mask)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}
