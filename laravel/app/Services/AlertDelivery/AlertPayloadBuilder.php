<?php

namespace App\Services\AlertDelivery;

use App\Models\LogMessage;
use App\Models\SavedQuery;

/**
 * Renders a SavedQuery + LogMessage match (or a system alert)
 * into the channel-agnostic payload dict shape AlertDriver expects:
 *
 *   {
 *     title:    string,
 *     body:     string,
 *     context:  string=>scalar
 *   }
 *
 * The drivers transform that into Slack blocks / webhook JSON / email
 * markdown — but ALL of them share the same source-of-truth shape,
 * which means a single audit row in `alert_deliveries.payload`
 * captures the deliverable in a format any driver can replay.
 */
class AlertPayloadBuilder
{
    /**
     * Render a payload for a saved-query → log-row match.
     *
     * @param  array{ subscription_id?: string, environment?: string }  $logContext
     */
    public function forSavedQueryMatch(SavedQuery $query, LogMessage $log, array $logContext = []): array
    {
        return [
            'title' => "Saved query «{$query->name}» matched",
            'body' => self::summariseLogRow($log),
            'context' => array_filter([
                'query_id' => $query->id,
                'log_message_id' => $log->id,
                'subscription_id' => $logContext['subscription_id'] ?? null,
                'environment' => $logContext['environment'] ?? null,
                'method' => $log->method,
                'status' => $log->status,
                'action' => $log->action,
                'path' => $log->path,
                'timestamp' => (string) $log->timestamp,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * Render a payload for a system-emitted alert (B5). `kind` is one
     * of the SystemAlertKind constants; `payload` carries the kind-
     * specific bag (session id, subscription id, etc.).
     *
     * @param  array<string,mixed>  $context
     */
    public function forSystemAlert(string $title, string $body, array $context): array
    {
        return [
            'title' => $title,
            'body' => $body,
            'context' => array_filter($context, fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * One-line summary used as the message body. Combines the most
     * load-bearing fields a paged operator wants on first read:
     * METHOD, status, action, and path.
     */
    private static function summariseLogRow(LogMessage $log): string
    {
        $parts = array_filter([
            $log->method ? strtoupper((string) $log->method) : null,
            $log->status ?: null,
            $log->action ?: null,
            $log->path ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        return $parts === [] ? '(no row metadata)' : implode(' · ', $parts);
    }
}
