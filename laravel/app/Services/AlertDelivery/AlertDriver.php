<?php

namespace App\Services\AlertDelivery;

use App\Models\AlertChannel;

/**
 * Strategy contract for the three delivery channel kinds:
 * SlackDriver, WebhookDriver, EmailDriver. Selected via
 * {@see DriverFactory::for($channel)} in DeliverAlertJob.
 *
 * Drivers MUST be deterministic (no `now()` inside the body) and
 * raise a throwable on failure — DeliverAlertJob catches the
 * throwable, stamps `alert_deliveries.error`, and lets Laravel's
 * queue retry the job with backoff.
 */
interface AlertDriver
{
    /**
     * Send the rendered payload through the channel.
     *
     * @param  array<string,mixed>  $payload
     *         Pre-rendered notification body shaped by
     *         {@see AlertPayloadBuilder}. The driver picks the keys
     *         it cares about (Slack: `text`/`blocks`; webhook: full
     *         payload; email: `subject`/`body`/`recipient_override?`).
     */
    public function send(AlertChannel $channel, array $payload): void;
}
