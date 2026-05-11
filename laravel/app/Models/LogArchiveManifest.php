<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id',
    'date',
    's3_key',
    'row_count',
    'archived_at',
])]
class LogArchiveManifest extends Model
{
    /**
     * Custom table name — using the migration's
     * `log_archive_manifest` (singular) name avoids forcing readers
     * to mentally pluralize when grepping the codebase. Eloquent's
     * default would have been `log_archive_manifests`.
     */
    protected $table = 'log_archive_manifest';

    protected function casts(): array
    {
        return [
            // `date` is intentionally NOT cast to a Carbon date —
            // see the migration's column type comment. The column
            // stores a plain `YYYY-MM-DD` string and we want to
            // surface it as such so manifest queries / dedup keys
            // match byte-for-byte with what the archive command
            // wrote.
            'row_count' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
