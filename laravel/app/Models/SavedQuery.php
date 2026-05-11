<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One named filter expression an operator wants to alert on.
 *
 * The `filter` column is a structured spec interpreted by
 * {@see \App\Services\AlertDelivery\SavedQueryEvaluator}. See the
 * 2026_05_11_191100_create_saved_queries_table migration for the
 * accepted shape; unknown keys are ignored so the spec can grow
 * without a migration.
 */
#[Fillable([
    'user_id',
    'name',
    'filter',
    'enabled',
])]
class SavedQuery extends Model
{
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(
            related: AlertChannel::class,
            table: 'saved_query_channel',
            foreignPivotKey: 'saved_query_id',
            relatedPivotKey: 'alert_channel_id',
        )->withPivot('dedupe_window_seconds')->withTimestamps();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }
}
