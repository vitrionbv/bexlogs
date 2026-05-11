<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'is_admin'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * Bootstrap model events.
     *
     * Guarantees the very first user created on a fresh install is an admin
     * — whether they sign up through the public registration form or are
     * created by the `admin:make` console command.
     */
    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (self::query()->count() === 0) {
                $user->is_admin = true;
            }
        });
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function bexSessions(): HasMany
    {
        return $this->hasMany(BexSession::class);
    }

    public function pairingTokens(): HasMany
    {
        return $this->hasMany(PairingToken::class);
    }

    public function savedFilters(): HasMany
    {
        return $this->hasMany(SavedFilter::class);
    }

    public function savedQueries(): HasMany
    {
        return $this->hasMany(SavedQuery::class);
    }

    public function alertChannels(): HasMany
    {
        return $this->hasMany(AlertChannel::class);
    }

    /**
     * Per-user choice of which alert channels receive system-emitted
     * alerts (B5: session expiry, consecutive failures, quiet
     * subscriptions). Independent of the per-saved-query channel
     * choices the operator wires up under B4.
     *
     * Stored in the `system_alert_channels` pivot keyed by
     * `(user_id, alert_channel_id)`.
     */
    public function systemAlertChannels(): BelongsToMany
    {
        return $this->belongsToMany(
            related: AlertChannel::class,
            table: 'system_alert_channels',
            foreignPivotKey: 'user_id',
            relatedPivotKey: 'alert_channel_id',
        )->withTimestamps();
    }

    /**
     * Pick the single best BexSession to use for this user-environment
     * pair. The picker is the read-side of the multi-session model
     * introduced in 2026_05_11_190000:
     *
     *   1. Prefer the lowest-`priority` HEALTHY session. Lower number
     *      wins; ties broken by id.
     *   2. If no healthy session exists, fall back to EXPIRING_SOON —
     *      these still authenticate, they're just on borrowed time.
     *   3. If neither tier returns a row, return null. The caller
     *      surfaces that as "no active session for {env}".
     *
     * EXPIRED and DISABLED rows are intentionally never returned: an
     * expired row will only retry-fail at the worker layer (and the
     * scheduler would burn its concurrency budget on it), and a
     * disabled row is the operator explicitly saying "skip me".
     *
     * The legacy `expired_at IS NULL` filter is preserved as a
     * belt-and-braces guard for any pre-migration row whose
     * health_status hasn't been backfilled by a session-save event yet.
     */
    public function activeBexSession(string $environment = 'production'): ?BexSession
    {
        $base = fn () => $this->bexSessions()
            ->where('environment', $environment)
            ->whereNull('expired_at')
            ->orderBy('priority')
            ->orderBy('id');

        return $base()->where('health_status', BexSession::HEALTH_HEALTHY)->first()
            ?? $base()->where('health_status', BexSession::HEALTH_EXPIRING_SOON)->first();
    }

    /**
     * All sessions for this user-env that are currently usable
     * (HEALTHY or EXPIRING_SOON). Ordered by priority, then id, so the
     * scheduler's round-robin index can be applied stably across ticks
     * — the same N-th index always returns the same session as long
     * as the underlying collection hasn't changed.
     *
     * @return Collection<int, BexSession>
     */
    public function activeBexSessions(string $environment = 'production'): Collection
    {
        return $this->bexSessions()
            ->where('environment', $environment)
            ->whereNull('expired_at')
            ->whereIn('health_status', [
                BexSession::HEALTH_HEALTHY,
                BexSession::HEALTH_EXPIRING_SOON,
            ])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }
}
