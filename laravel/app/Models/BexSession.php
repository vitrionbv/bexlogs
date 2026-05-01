<?php

namespace App\Models;

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
}
