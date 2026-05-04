<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable([
    'user_id',
    'environment',
    'cookies_encrypted',
    'account_email',
    'account_name',
    'captured_at',
    'last_validated_at',
    'expired_at',
])]
class BexSession extends Model
{
    /**
     * Cookie names that actually carry auth for BookingExperts. Used to
     * compute a sensible Cookie TTL label on the Authenticate page —
     * everything else in the captured jar (CSRF tokens, locale, A/B
     * bucketing, FullStory, Hotjar, …) is chaff with TTLs ranging from
     * minutes to days and would otherwise drag the surfaced "expires
     * in" reading way below the auth cookie's real lifetime, or worse
     * make a still-valid session look "cookies expired" hours after
     * capture.
     *
     * Patterns are PCRE; case-insensitive. They cover the Rails
     * `_<app>_session` family (`_BookingExperts_session`,
     * `_app_session`, `_bex_session`) and Devise's "remember me"
     * cookie. If BE introduces a new auth-bearing cookie, add its
     * pattern here — the TTL label is the only thing that depends on
     * this list, so the worst-case fallout of a missed addition is a
     * cosmetic "Unknown" label instead of a precise countdown.
     */
    public const AUTH_COOKIE_PATTERNS = [
        '#(?:_app_session|_session)#i',
        '#remember_user_token#i',
    ];

    protected $hidden = ['cookies_encrypted'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'last_validated_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /**
     * Plaintext cookies array.
     *
     * Reads/writes the encrypted cookies_encrypted column transparently.
     * The value is an array of Chrome-style cookie objects:
     *   [{ name, value, domain, path, expirationDate?, httpOnly, secure, sameSite }]
     */
    protected function cookies(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cookies_encrypted
                ? json_decode(Crypt::decryptString($this->cookies_encrypted), true)
                : [],
            set: fn (array $value) => [
                'cookies_encrypted' => Crypt::encryptString(json_encode($value)),
            ],
        );
    }

    public function isExpired(): bool
    {
        return $this->expired_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Subset of {@see cookies()} matching {@see AUTH_COOKIE_PATTERNS}.
     * The caller is responsible for handling the empty-array case.
     *
     * @return list<array<string, mixed>>
     */
    public function authCookies(): array
    {
        $cookies = $this->cookies ?? [];
        $matches = [];

        foreach ($cookies as $cookie) {
            $name = (string) ($cookie['name'] ?? '');
            if ($name === '') {
                continue;
            }

            foreach (self::AUTH_COOKIE_PATTERNS as $pattern) {
                if (preg_match($pattern, $name)) {
                    $matches[] = $cookie;

                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Compute a UI-friendly Cookie TTL summary for this session. Drives
     * the Authenticate page's "Cookie TTL" badge.
     *
     * The historical implementation (AuthenticateController) returned
     * `min(expirationDate)` across the entire cookie jar, which:
     *   1. Reported "cookies expired" whenever ANY chaff cookie (e.g. an
     *      hour-long A/B bucket) had elapsed, even if the auth cookie
     *      was still good for weeks.
     *   2. Treated the absence of `expirationDate` (i.e. session-only
     *      cookies) as "no information" by silently skipping the cookie
     *      — but only because the loop guarded `! empty(...)`. A row
     *      whose auth cookie was session-only would either show
     *      whatever chaff cookie set the earliest expiry, or nothing.
     *
     * The new logic looks at AUTH_COOKIE_PATTERNS only, picks the
     * **maximum** expirationDate (longest-lived auth cookie wins —
     * Devise's remember_user_token usually outlives the rolling Rails
     * session), and special-cases the no-Expires "session cookie" case
     * so it gets a neutral "Session cookie" label instead of being
     * mislabelled "expired".
     *
     * Tones map to Badge variants:
     *   - success     long-lived (>7d remaining)
     *   - warning     short-lived (<=7d remaining) or session-only
     *   - destructive auth cookie's Expires has already passed
     *   - outline     neutral fallback (no auth cookies in the jar)
     *
     * @return array{
     *   label: string,
     *   tone: 'success'|'warning'|'destructive'|'outline',
     *   expires_at: ?string,
     *   kind: 'absolute'|'session'|'expired'|'unknown',
     * }
     */
    public function cookieTtlSummary(?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $authCookies = $this->authCookies();

        if ($authCookies === []) {
            return [
                'label' => 'Unknown',
                'tone' => 'outline',
                'expires_at' => null,
                'kind' => 'unknown',
            ];
        }

        $maxExpires = null;
        $sawExpiresField = false;
        foreach ($authCookies as $cookie) {
            if (! array_key_exists('expirationDate', $cookie)) {
                continue;
            }
            $value = $cookie['expirationDate'];
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            if (! is_numeric($value)) {
                continue;
            }

            $sawExpiresField = true;
            $ts = (int) $value;
            if ($maxExpires === null || $ts > $maxExpires) {
                $maxExpires = $ts;
            }
        }

        if (! $sawExpiresField) {
            return [
                'label' => 'Session cookie',
                'tone' => 'warning',
                'expires_at' => null,
                'kind' => 'session',
            ];
        }

        $expires = CarbonImmutable::createFromTimestamp((int) $maxExpires);

        if ($expires->isPast($now)) {
            return [
                'label' => 'Expired '.self::humanDelta($now->diffInSeconds($expires, true)).' ago',
                'tone' => 'destructive',
                'expires_at' => $expires->toIso8601String(),
                'kind' => 'expired',
            ];
        }

        $secondsLeft = $expires->diffInSeconds($now, true);
        $tone = $secondsLeft >= 7 * 86400 ? 'success' : 'warning';

        return [
            'label' => 'Expires in '.self::humanDelta($secondsLeft),
            'tone' => $tone,
            'expires_at' => $expires->toIso8601String(),
            'kind' => 'absolute',
        ];
    }

    /**
     * Render an integer second-count as a coarse "Xd Yh", "Xh Ym", or
     * "Xm" string. Always positive. Used by {@see cookieTtlSummary}
     * for both the "Expires in …" and "Expired … ago" labels so the
     * grain is consistent in both directions.
     */
    private static function humanDelta(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds >= 86400) {
            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);

            return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }

        if ($seconds >= 3600) {
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        }

        if ($seconds >= 60) {
            $minutes = intdiv($seconds, 60);

            return "{$minutes}m";
        }

        return "{$seconds}s";
    }
}
