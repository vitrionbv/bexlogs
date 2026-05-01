<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    public function activeBexSession(string $environment = 'production'): ?BexSession
    {
        return $this->bexSessions()
            ->where('environment', $environment)
            ->whereNull('expired_at')
            ->latest('captured_at')
            ->first();
    }
}
