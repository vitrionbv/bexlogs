<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'token',
    'user_id',
    'environment',
    'expires_at',
    'consumed_at',
    'bex_session_id',
])]
class PairingToken extends Model
{
    protected $primaryKey = 'token';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public static function generate(int $userId, string $environment, int $ttlMinutes = 5): self
    {
        return self::create([
            'token' => Str::random(48),
            'user_id' => $userId,
            'environment' => $environment,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bexSession(): BelongsTo
    {
        return $this->belongsTo(BexSession::class);
    }
}
