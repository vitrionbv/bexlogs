<?php

namespace App\Models;

use App\Jobs\DeliverAlertJob;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row for a single attempted delivery to an AlertChannel. Persisted
 * by {@see DeliverAlertJob} both before the HTTP/SMTP call
 * (status=queued) and after (status=sent or failed). Surfaced as a
 * "recent deliveries" list per query and per channel in the /alerts
 * UI so an operator can answer "did Slack get the alert?" without
 * grepping container logs.
 *
 * Statuses:
 *   - `queued`  the row was created but the job hasn't tried delivery yet
 *               (window between job enqueue and the queue worker
 *               picking it up).
 *   - `sent`    delivery returned a 2xx (HTTP) or didn't throw (mail).
 *   - `failed`  exception bubbled or non-2xx response. The throwable
 *               message is captured in `error` for the UI.
 *
 * `payload` carries the rendered notification body — Slack blocks,
 * the JSON body POSTed to a webhook, or the markdown email payload —
 * so an operator can replay a delivery (re-POST to the same channel)
 * from the UI without re-running the whole match → render pipeline.
 */
#[Fillable([
    'saved_query_id',
    'alert_channel_id',
    'log_message_id',
    'payload',
    'status',
    'attempts',
    'last_attempt_at',
    'error',
])]
class AlertDelivery extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function savedQuery(): BelongsTo
    {
        return $this->belongsTo(SavedQuery::class);
    }

    public function alertChannel(): BelongsTo
    {
        return $this->belongsTo(AlertChannel::class);
    }

    public function logMessage(): BelongsTo
    {
        return $this->belongsTo(LogMessage::class);
    }
}
