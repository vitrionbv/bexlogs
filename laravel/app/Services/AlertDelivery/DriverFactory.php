<?php

namespace App\Services\AlertDelivery;

use App\Models\AlertChannel;
use InvalidArgumentException;

/**
 * Maps an {@see AlertChannel}'s `kind` to the matching {@see AlertDriver}.
 * Resolved through the container so individual drivers can have their
 * own constructor dependencies (HTTP client, mailer, etc.) injected
 * by Laravel without DeliverAlertJob having to know.
 */
class DriverFactory
{
    public function for(AlertChannel $channel): AlertDriver
    {
        return match ($channel->kind) {
            AlertChannel::KIND_SLACK => app(SlackDriver::class),
            AlertChannel::KIND_WEBHOOK => app(WebhookDriver::class),
            AlertChannel::KIND_EMAIL => app(EmailDriver::class),
            default => throw new InvalidArgumentException(
                "DriverFactory: unknown channel kind [{$channel->kind}]"
            ),
        };
    }
}
