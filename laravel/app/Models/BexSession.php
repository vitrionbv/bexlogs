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
    'priority',
    'expires_at',
    'health_status',
])]
class BexSession extends Model
{
    /**
     * Health status values stored verbatim in the `health_status` column.
     * Mirrors the CHECK constraint set up in the
     * 2026_05_11_190000_add_multi_session_fields migration. The
     * scheduler picks `HEALTHY` first, falls back to `EXPIRING_SOON`,
     * and skips `EXPIRED` / `DISABLED` outright.
     */
    public const HEALTH_HEALTHY = 'healthy';

    public const HEALTH_EXPIRING_SOON = 'expiring_soon';

    public const HEALTH_EXPIRED = 'expired';

    public const HEALTH_DISABLED = 'disabled';

    public const HEALTH_STATUSES = [
        self::HEALTH_HEALTHY,
        self::HEALTH_EXPIRING_SOON,
        self::HEALTH_EXPIRED,
        self::HEALTH_DISABLED,
    ];

    /**
     * Threshold for "expiring soon" — anything within this window of
     * `now` is bumped from `healthy` to `expiring_soon`. Operators
     * still get to use the session (see User::activeBexSession), but
     * the alerting layer (B5: bex:check-sessions) treats the same
     * window as the "warn the operator" trigger.
     */
    public const EXPIRING_SOON_WINDOW_HOURS = 48;

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

    /**
     * Auto-derive `expires_at` and `health_status` whenever the row is
     * saved with new cookies or the validator just flipped
     * `expired_at`. Mutates in-memory attributes so the original write
     * carries them — cleaner than calling save() recursively.
     *
     * The hook intentionally bails out when only unrelated columns
     * changed (e.g. last_validated_at on a healthy refresh) — recomputing
     * is cheap but avoids spurious health-status updates that would
     * trigger downstream alert evaluations.
     *
     * Operator-set DISABLED is sticky: once an operator clicks
     * "Disable" on a session row in the UI, no automatic flow will
     * promote it back. They must explicitly toggle it back on.
     */
    protected static function booted(): void
    {
        static::saving(function (self $session): void {
            if ($session->health_status === self::HEALTH_DISABLED) {
                return;
            }

            $touchesCookies = $session->isDirty('cookies_encrypted')
                || $session->isDirty('expired_at')
                || ! $session->exists;

            if (! $touchesCookies) {
                return;
            }

            $now = CarbonImmutable::now();
            $derivedExpires = $session->deriveExpiresAt();

            $session->expires_at = $derivedExpires;
            $session->health_status = self::resolveHealth(
                expiredAt: $session->expired_at,
                expiresAt: $derivedExpires,
                now: $now,
            );
        });
    }

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'last_validated_at' => 'datetime',
            'expired_at' => 'datetime',
            'expires_at' => 'datetime',
            'priority' => 'integer',
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
     * Compute the "longest-lived auth cookie" timestamp the same way
     * {@see cookieTtlSummary} does, but as a raw Carbon (or null). This
     * is what gets persisted into the `expires_at` column whenever
     * cookies change so the rotation picker doesn't have to JSON-decode
     * the cookie jar on every scheduler tick.
     *
     * Returns null when the auth cookie is session-only or absent —
     * the caller decides what to do with that (we treat null as
     * "expiring soon" because session cookies die with the next browser
     * quit).
     */
    public function deriveExpiresAt(): ?CarbonImmutable
    {
        $authCookies = $this->authCookies();
        if ($authCookies === []) {
            return null;
        }

        $maxExpires = null;
        foreach ($authCookies as $cookie) {
            if (! array_key_exists('expirationDate', $cookie)) {
                continue;
            }
            $value = $cookie['expirationDate'];
            if ($value === null || $value === '' || $value === false || ! is_numeric($value)) {
                continue;
            }

            $ts = (int) $value;
            if ($maxExpires === null || $ts > $maxExpires) {
                $maxExpires = $ts;
            }
        }

        return $maxExpires === null ? null : CarbonImmutable::createFromTimestamp($maxExpires);
    }

    /**
     * Re-derive `expires_at` and `health_status` from the current
     * cookies + validator state and persist them. Idempotent — calling
     * this twice in a row is a no-op the second time.
     *
     * This is the explicit-rebuild entry point used by `bex:check-sessions`
     * (B5) when it's promoting a session across the 48h boundary; the
     * implicit `booted()` `saving` hook only fires on an actual write.
     *
     * @param  ?CarbonImmutable  $now  Override for tests; defaults to
     *                                 `CarbonImmutable::now()`.
     */
    public function recomputeHealth(?CarbonImmutable $now = null): self
    {
        if ($this->health_status === self::HEALTH_DISABLED) {
            return $this;
        }

        $now = $now ?? CarbonImmutable::now();
        $derivedExpires = $this->deriveExpiresAt();
        $health = self::resolveHealth($this->expired_at, $derivedExpires, $now);

        $this->forceFill([
            'expires_at' => $derivedExpires,
            'health_status' => $health,
        ])->save();

        return $this;
    }

    /**
     * Pure decision function shared by the saving hook and the
     * explicit recompute path. Health resolution priority:
     *
     *   1. validator-rejected (`expired_at IS NOT NULL`) → expired.
     *   2. cookies past their Expires → expired.
     *   3. cookies < EXPIRING_SOON_WINDOW_HOURS to expiry, OR
     *      session-only auth cookies → expiring_soon.
     *   4. otherwise → healthy.
     */
    private static function resolveHealth(
        $expiredAt,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $now,
    ): string {
        return match (true) {
            $expiredAt !== null => self::HEALTH_EXPIRED,
            $expiresAt !== null && $expiresAt->lessThanOrEqualTo($now) => self::HEALTH_EXPIRED,
            $expiresAt === null => self::HEALTH_EXPIRING_SOON,
            $expiresAt->diffInHours($now, true) <= self::EXPIRING_SOON_WINDOW_HOURS => self::HEALTH_EXPIRING_SOON,
            default => self::HEALTH_HEALTHY,
        };
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
