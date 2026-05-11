<?php

namespace App\Models;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Database\Factories\ScrapeJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id',
    'bex_session_id',
    'status',
    'attempts',
    'params',
    'started_at',
    'completed_at',
    'last_heartbeat_at',
    'error',
    'stats',
])]
#[ApiResource(
    shortName: 'ScrapeJob',
    operations: [
        new GetCollection(uriTemplate: '/scrape-jobs{._format}'),
        new Get(uriTemplate: '/scrape-jobs/{id}{._format}'),
    ],
)]
class ScrapeJob extends Model
{
    /** @use HasFactory<ScrapeJobFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'stats' => 'array',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function bexSession(): BelongsTo
    {
        return $this->belongsTo(BexSession::class);
    }
}
