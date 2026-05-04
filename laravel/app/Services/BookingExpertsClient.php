<?php

namespace App\Services;

use App\Models\BexSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for BookingExperts. Used by Laravel for cookie validation
 * (the heavy scraping is done by the Node Playwright worker).
 */
class BookingExpertsClient
{
    /**
     * Cap on the number of redirects validateSession() will follow before
     * giving up with reason=redirect_loop. Matches Guzzle's default `max`
     * for the allow_redirects middleware (5). Worst-case HTTP cost per
     * invocation is therefore 1 initial GET + up to 5 follows = 6 calls.
     */
    private const MAX_REDIRECT_HOPS = 5;

    /**
     * Path fragments that, when seen as a Location target (or as the
     * final URL we land on), mark the session as expired. BookingExperts
     * funnels unauthenticated traffic through Devise's /users/sign_in;
     * older paths used /sign_in and /users/login. Word boundary guards
     * against false positives like /sign_in_help.
     */
    private const SIGN_IN_PATTERN = '#/(?:users/)?(?:sign_in|login)\b#i';

    public function __construct(private readonly string $environment = 'production') {}

    public function baseUrl(): string
    {
        return $this->environment === 'staging'
            ? config('bex.staging_url', 'https://app.staging.bookingexperts.com')
            : config('bex.production_url', 'https://app.bookingexperts.com');
    }

    /**
     * Build a PendingRequest with the session cookies attached as a Cookie header.
     *
     * `allow_redirects` is disabled deliberately so the validator (and
     * any other caller that needs to inspect intermediate hops) can walk
     * the chain manually. BookingExpertsBrowser overrides this with
     * allow_redirects=true for its scraping path.
     */
    public function authed(BexSession $session): PendingRequest
    {
        $cookieHeader = $this->cookieHeader($session->cookies);

        return Http::withHeaders([
            'Cookie' => $cookieHeader,
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
            'Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.8',
        ])
            ->withOptions(['allow_redirects' => false])
            ->baseUrl($this->baseUrl());
    }

    /**
     * Validate a session by walking the redirect chain off `GET /` (up
     * to MAX_REDIRECT_HOPS hops) until we hit a terminal response. The
     * verdict is:
     *
     *   - reason=expired         when ANY Location header in the chain
     *                            (or the final URL) matches
     *                            /sign_in / /users/sign_in / /users/login
     *   - reason=ok              when the chain ends in a 2xx page that
     *                            isn't itself a sign-in URL; identity
     *                            (email + name) is extracted from the body
     *   - reason=unknown         for terminal 4xx/5xx responses, or 3xx
     *                            without a Location, or anything else
     *                            we can't classify confidently. Logged
     *                            at warning so the operator can dig in.
     *   - reason=redirect_loop   when the chain exceeds MAX_REDIRECT_HOPS
     *                            without resolving
     *   - reason=network         when Guzzle throws ConnectionException
     *                            (DNS / TCP failure). Logged at warning;
     *                            does NOT mark the session expired.
     *
     * The previous implementation only inspected the FIRST redirect's
     * Location header, which made stale production sessions look valid:
     * BookingExperts redirects unauthenticated `GET /` to a neutral
     * `/redirect?locale=nl` first, only sending `/users/sign_in` on the
     * second hop. See deploy/README.md, "Diagnosing Session expired
     * failures", for the full diagnosis trail.
     *
     * @return array{
     *   valid: bool,
     *   reason: 'ok'|'expired'|'unknown'|'redirect_loop'|'network',
     *   status: int,
     *   redirect: ?string,
     *   chain: list<string>,
     *   email: ?string,
     *   name: ?string,
     * }
     */
    public function validateSession(BexSession $session): array
    {
        $client = $this->authed($session);
        $url = rtrim($this->baseUrl(), '/').'/';
        $chain = [];
        $redirectsFollowed = 0;

        try {
            while (true) {
                $resp = $client->get($url);
                $status = $resp->status();
                $location = $resp->header('Location');
                $chain[] = $this->describeHop($status, $url, $location);

                $locationIsSignIn = $location !== null && $location !== ''
                    && (bool) preg_match(self::SIGN_IN_PATTERN, $location);
                if ($locationIsSignIn) {
                    return $this->verdict(
                        valid: false,
                        reason: 'expired',
                        status: $status,
                        redirect: $location,
                        chain: $chain,
                        session: $session,
                    );
                }

                if ($status >= 200 && $status < 300) {
                    if (preg_match(self::SIGN_IN_PATTERN, $url)) {
                        return $this->verdict(
                            valid: false,
                            reason: 'expired',
                            status: $status,
                            redirect: null,
                            chain: $chain,
                            session: $session,
                        );
                    }

                    [$email, $name] = $this->extractUserIdentity($resp->body());

                    return $this->verdict(
                        valid: true,
                        reason: 'ok',
                        status: $status,
                        redirect: null,
                        chain: $chain,
                        session: $session,
                        email: $email,
                        name: $name,
                    );
                }

                $isRedirect = $status >= 300 && $status < 400;
                if (! $isRedirect || $location === null || $location === '') {
                    return $this->verdict(
                        valid: false,
                        reason: 'unknown',
                        status: $status,
                        redirect: $location ?: null,
                        chain: $chain,
                        session: $session,
                    );
                }

                if ($redirectsFollowed >= self::MAX_REDIRECT_HOPS) {
                    return $this->verdict(
                        valid: false,
                        reason: 'redirect_loop',
                        status: $status,
                        redirect: $location,
                        chain: $chain,
                        session: $session,
                    );
                }

                $url = $this->resolveRedirect($url, $location);
                $redirectsFollowed++;
            }
        } catch (ConnectionException $e) {
            Log::warning('bex validateSession: network error talking to BookingExperts', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
                'chain' => $chain,
            ]);

            return [
                'valid' => false,
                'reason' => 'network',
                'status' => 0,
                'redirect' => null,
                'chain' => $chain,
                'email' => null,
                'name' => null,
            ];
        }
    }

    /**
     * Build the standard verdict array and emit a log line on the
     * non-valid paths so we can reconstruct WHY a session was marked
     * invalid (chain of URLs traversed). expired is logged at debug
     * (it's the expected steady-state for stale sessions); the rest
     * (unknown / redirect_loop) at warning so operators notice.
     *
     * @param  list<string>  $chain
     * @return array{
     *   valid: bool,
     *   reason: 'ok'|'expired'|'unknown'|'redirect_loop'|'network',
     *   status: int,
     *   redirect: ?string,
     *   chain: list<string>,
     *   email: ?string,
     *   name: ?string,
     * }
     */
    private function verdict(
        bool $valid,
        string $reason,
        int $status,
        ?string $redirect,
        array $chain,
        BexSession $session,
        ?string $email = null,
        ?string $name = null,
    ): array {
        if (! $valid) {
            $level = $reason === 'expired' ? 'debug' : 'warning';
            Log::log($level, 'bex validateSession marked session invalid', [
                'session_id' => $session->id,
                'reason' => $reason,
                'status' => $status,
                'final_redirect' => $redirect,
                'chain' => $chain,
            ]);
        }

        return [
            'valid' => $valid,
            'reason' => $reason,
            'status' => $status,
            'redirect' => $redirect,
            'chain' => $chain,
            'email' => $email,
            'name' => $name,
        ];
    }

    private function describeHop(int $status, string $url, ?string $location): string
    {
        return $location !== null && $location !== ''
            ? sprintf('%d %s -> %s', $status, $url, $location)
            : sprintf('%d %s', $status, $url);
    }

    /**
     * Resolve a Location header value against the current absolute URL,
     * always returning an absolute URL. Handles the three flavours BE
     * actually uses (absolute, root-relative, scheme-relative) plus a
     * defensive path-relative fallback.
     */
    private function resolveRedirect(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $base = rtrim($this->baseUrl(), '/');

        if (str_starts_with($location, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $base.$location;
        }

        $currentAbs = preg_match('#^https?://#i', $current)
            ? $current
            : $base.'/'.ltrim($current, '/');

        return preg_replace('#/[^/]*(?:\?.*)?$#', '/', $currentAbs).$location;
    }

    /**
     * Best-effort extraction of the logged-in user's email + display name from
     * the rendered home page. BookingExperts puts both inside the user-menu
     * dropdown ("Sherin Bloemendaal" / "sherin@verbleif.com"). The selectors
     * shift over time so we use heuristics rather than a strict CSS path.
     *
     * @return array{0: ?string, 1: ?string} [email, name]
     */
    public function extractUserIdentity(string $html): array
    {
        $email = null;
        if (preg_match(
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
            $html,
            $matches,
        )) {
            // Skip obvious noise: support@bookingexperts addresses, etc.
            $candidate = $matches[0];
            if (! str_contains($candidate, 'support@') && ! str_contains($candidate, 'noreply')) {
                $email = $candidate;
            }
        }

        $name = null;
        if (preg_match(
            '#<(?:a|div|span)[^>]+(?:user(?:-?(?:menu|info|name))?|profile-name|account-name)[^>]*>\s*([^<]{2,80})\s*<#i',
            $html,
            $m,
        )) {
            $name = trim($m[1]);
        } elseif ($email && preg_match(
            '#<(?:span|div|a)[^>]*>\s*([A-Z][A-Za-zÀ-ÿ\.\-\']{1,40}(?:\s+[A-Z][A-Za-zÀ-ÿ\.\-\']{1,40}){0,3})\s*</(?:span|div|a)>\s*<[^>]*>'.preg_quote($email, '#').'#',
            $html,
            $m,
        )) {
            $name = trim($m[1]);
        }

        return [$email, $name];
    }

    /**
     * Convert a Chrome-style cookie array to a single Cookie request header value.
     *
     * @param  array<int, array{name:string,value:string,domain?:string,path?:string,expirationDate?:float|int,httpOnly?:bool,secure?:bool,sameSite?:string}>  $cookies
     */
    private function cookieHeader(array $cookies): string
    {
        $relevant = collect($cookies)
            ->filter(fn (array $c) => isset($c['name'], $c['value']))
            ->filter(function (array $c) {
                $domain = ltrim($c['domain'] ?? '', '.');

                return $domain === '' || str_ends_with('app.bookingexperts.com', $domain) || str_ends_with('app.staging.bookingexperts.com', $domain);
            })
            ->map(fn (array $c) => "{$c['name']}={$c['value']}")
            ->implode('; ');

        return $relevant;
    }
}
