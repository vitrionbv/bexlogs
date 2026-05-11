<?php

namespace App\Models;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Database\Factories\LogMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'page_id',
    'timestamp',
    'type',
    'action',
    'method',
    'path',
    'status',
    'parameters',
    'request',
    'response',
    'content_hash',
])]
#[ApiResource(
    shortName: 'LogMessage',
    operations: [
        new GetCollection(uriTemplate: '/log-messages{._format}'),
        new Get(uriTemplate: '/log-messages/{id}{._format}'),
    ],
    paginationItemsPerPage: 30,
    paginationMaximumItemsPerPage: 100,
    paginationClientItemsPerPage: true,
)]
class LogMessage extends Model
{
    /** @use HasFactory<LogMessageFactory> */
    use HasFactory;

    /**
     * Postgres returns `content_hash` (bytea) as a PHP stream resource through
     * PDO, which json_encode rejects with "Type is not supported". The hash is
     * only used internally for upsert dedup, so we hide it from any model →
     * array / JSON conversion (Inertia responses, API payloads, exports).
     */
    protected $hidden = ['content_hash'];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'request' => 'array',
            'response' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
