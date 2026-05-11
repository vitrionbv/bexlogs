<?php

namespace App\Services\AlertDelivery;

use App\Models\AlertChannel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Slack incoming-webhook driver. Posts a JSON body Slack will render
 * as a single message in the destination channel.
 *
 * Why incoming-webhook (legacy) over the Bot API: incoming webhooks
 * are zero-config from the operator's perspective ("paste a URL into
 * the box"), they don't require a bot user in every Slack workspace,
 * and the on-disk secret is just the URL — easier to rotate.
 *
 * The body shape mirrors what the Slack docs call the "blocks"
 * payload: a `text` fallback (used by mobile push and notifications)
 * plus a single section block with the human-readable title and a
 * context block with the metadata. Keeping the layout consistent
 * across delivery channels means an operator looking at a Slack
 * thread vs. their email inbox sees the same hierarchy.
 */
class SlackDriver implements AlertDriver
{
    public function send(AlertChannel $channel, array $payload): void
    {
        $cfg = $channel->config_encrypted ?? [];
        $url = (string) ($cfg['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('SlackDriver: channel has no webhook URL configured.');
        }

        $title = (string) ($payload['title'] ?? 'BexLogs alert');
        $body = (string) ($payload['body'] ?? '');
        $context = (array) ($payload['context'] ?? []);

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => self::truncate($title, 150)],
            ],
        ];

        if ($body !== '') {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => self::truncate($body, 2900)],
            ];
        }

        if ($context !== []) {
            $elements = [];
            foreach ($context as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $elements[] = [
                    'type' => 'mrkdwn',
                    'text' => '*'.$key.':* '.self::truncate((string) $value, 200),
                ];
            }
            if ($elements !== []) {
                $blocks[] = ['type' => 'context', 'elements' => $elements];
            }
        }

        // Cap at 5s so a hung Slack endpoint can't pin the queue
        // worker indefinitely. Slack returns < 200ms in practice;
        // anything past 5s is a real outage we want the queue to
        // surface as a retryable failure.
        $response = Http::timeout(5)
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'text' => self::truncate($title.': '.$body, 500),
                'blocks' => $blocks,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'SlackDriver: webhook returned HTTP '.$response->status().' — '.self::truncate($response->body(), 200),
            );
        }
    }

    private static function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, max(0, $max - 1)).'…';
    }
}
