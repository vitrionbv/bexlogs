<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
class LogMessage extends Model
{
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
