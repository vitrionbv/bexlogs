<?php

namespace App\Services\AlertDelivery;

use App\Mail\AlertNotificationMail;
use App\Models\AlertChannel;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Email channel driver. Wraps Laravel's Mail facade so the existing
 * SMTP / SES / SendGrid configuration in `config/mail.php` is what
 * gets used — no per-channel transport setup.
 *
 * The recipient comes out of `config.to_address`. Multiple recipients
 * per channel are intentionally NOT supported on a single channel row
 * because the per-saved-query / per-system-alert pivot already lets
 * an operator wire multiple email channels into the same trigger; one
 * channel = one mailbox keeps the audit trail (alert_deliveries)
 * one-row-per-recipient instead of having to thread through the
 * payload array.
 */
class EmailDriver implements AlertDriver
{
    public function send(AlertChannel $channel, array $payload): void
    {
        $cfg = $channel->config_encrypted ?? [];
        $to = (string) ($cfg['to_address'] ?? '');
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('EmailDriver: channel has no valid to_address configured.');
        }

        Mail::to($to)->send(new AlertNotificationMail(
            title: (string) ($payload['title'] ?? 'BexLogs alert'),
            body: (string) ($payload['body'] ?? ''),
            context: (array) ($payload['context'] ?? []),
        ));
    }
}
