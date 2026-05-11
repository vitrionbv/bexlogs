<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The single email shape used for both saved-query alerts (B4) and
 * system alerts (B5). The Markdown view renders the title as the
 * subject and the body + context table as the message.
 *
 * Kept intentionally simple — this is operator-facing, not a
 * customer-facing transactional email — so we don't need the full
 * Mail::brand / theme apparatus. The subject is prefixed with
 * `[BexLogs]` so a Gmail filter can route alerts to a folder.
 */
class AlertNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string,mixed>  $context
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $context = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[BexLogs] '.$this->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.alert_notification',
            with: [
                'title' => $this->title,
                'body' => $this->body,
                'context' => $this->context,
            ],
        );
    }
}
