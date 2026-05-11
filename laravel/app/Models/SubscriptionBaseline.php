<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id',
    'duration_p50',
    'duration_p95',
    'duration_p99',
    'rows_inserted_p50',
    'rows_inserted_p95',
    'rows_inserted_p99',
    'same_hour_avg_rows_inserted',
    'sample_size',
    'window_from',
    'window_to',
    'computed_at',
])]
class SubscriptionBaseline extends Model
{
    protected function casts(): array
    {
        return [
            'duration_p50' => 'float',
            'duration_p95' => 'float',
            'duration_p99' => 'float',
            'rows_inserted_p50' => 'float',
            'rows_inserted_p95' => 'float',
            'rows_inserted_p99' => 'float',
            'same_hour_avg_rows_inserted' => 'array',
            'sample_size' => 'integer',
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
