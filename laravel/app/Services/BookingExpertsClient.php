<?php

namespace App\Services;

use App\Models\BexSession;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for BookingExperts. Used by Laravel for cookie validation
 * (the heavy scraping is done by the Node Playwright worker).
 */
class BookingExpertsClient
{
    public function __construct(private readonly string $environment = 'production') {}

    public function baseUrl(): string
    {
        return $this->environment === 'staging'
            ? config('bex.staging_url', 'https://app.staging.bookingexperts.com')
            : config('bex.production_url', 'https://app.bookingexperts.com');
    }

    /**
     * Build a PendingRequest with the session cookies attached as a Cookie header.
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
     * Validate a session by GETting "/" and checking it doesn't 302 to /sign_in.
     * Also tries to extract the user's email + display name from the rendered
     * HTML so we can label sessions in the UI.
     *
     * @return array{valid: bool, status: int, redirect: ?string, email: ?string, name: ?string}
     */
    public function validateSession(BexSession $session): array
    {
        $resp = $this->authed($session)->get('/');
        $status = $resp->status();
        $location = $resp->header('Location');

        $valid = $status === 200
            || ($status >= 300 && $status < 400 && ! str_contains((string) $location, '/sign_in'));

        [$email, $name] = $valid && $status === 200
            ? $this->extractUserIdentity($resp->body())
            : [null, null];

        return [
            'valid' => $valid,
            'status' => $status,
            'redirect' => $location ?: null,
            'email' => $email,
            'name' => $name,
        ];
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
