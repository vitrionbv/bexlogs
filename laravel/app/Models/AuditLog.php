<?php

namespace App\Models;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable activity-stream row written by {@see AuditLogger}.
 *
 * The model is read-mostly: the only write path is `AuditLogger::record()`
 * (and the observers under `App\Observers\Audit*`). Treat instances
 * returned from the database as immutable from a domain perspective —
 * we intentionally do NOT expose update/delete helpers.
 *
 * `updated_at` is unmodelled: the table only has `created_at`, so we
 * disable the timestamp updater here and use a manual `created_at` cast.
 */
#[Fillable([
    'user_id',
    'action',
    'subject_type',
    'subject_id',
    'payload',
    'ip_address',
    'user_agent',
])]
class AuditLog extends Model
{
    /**
     * Audit table only has `created_at`; Laravel's default
     * timestamp-pair convention would try to fill `updated_at` and
     * break inserts. Disable the auto-timestamping and supply our own
     * cast for `created_at` below.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user who initiated the action — null for system-emitted
     * events (e.g. the scheduler auto-expiring a session).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Polymorphic relation back to whatever the action targeted
     * (Subscription, BexSession, ScrapeJob, …). The reverse side is
     * unmodelled — we don't lean on `Subject::auditLogs()` anywhere
     * (the Activity page queries this table directly with filters).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope helper used by the Activity page filters. Each argument
     * is optional; an empty value short-circuits the constraint so
     * the controller can pass through query-string values without
     * branching on emptiness.
     */
    public function scopeFilter(Builder $q, array $filters): Builder
    {
        if (! empty($filters['user_id'])) {
            $q->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['actions']) && is_array($filters['actions'])) {
            $q->whereIn('action', $filters['actions']);
        }

        if (! empty($filters['subject_type']) && ! empty($filters['subject_id'])) {
            $q->where('subject_type', $filters['subject_type'])
                ->where('subject_id', $filters['subject_id']);
        }

        if (! empty($filters['from'])) {
            $q->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $q->where('created_at', '<=', $filters['to']);
        }

        return $q;
    }
}
