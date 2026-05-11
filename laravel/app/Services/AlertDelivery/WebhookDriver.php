<?php

namespace App\Services\AlertDelivery;

use App\Models\AlertChannel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Generic webhook driver. POSTs the rendered payload as JSON to the
 * operator-supplied URL, optionally signed with HMAC-SHA256 over the
 * raw body using `config.secret` so the receiver can verify
 * authenticity.
 *
 * Signature header `X-BexLogs-Signature: sha256=<hex>` mirrors the
 * GitHub webhook convention so operators piping into PagerDuty's
 * Events V2 API or a homemade receiver can use any off-the-shelf
 * signature-verification middleware.
 *
 * The request body is the FULL payload dict — title, body, context,
 * and any source metadata — so a downstream router (Workato / Zapier /
 * a hand-rolled Lambda) has everything it needs to fan out further
 * without a callback to BexLogs.
 */
class WebhookDriver implements AlertDriver
{
    public function send(AlertChannel $channel, array $payload): void
    {
        $cfg = $channel->config_encrypted ?? [];
        $url = (string) ($cfg['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('WebhookDriver: channel has no URL configured.');
        }

        $secret = (string) ($cfg['secret'] ?? '');
        // JSON_UNESCAPED_SLASHES keeps the body byte-identical to what
        // the receiver sees, which matters because the HMAC is
        // computed over the literal bytes — any re-encoding (a JSON
        // pretty-printer in middleware, for instance) would break
        // verification.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('WebhookDriver: failed to JSON-encode payload.');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'BexLogs-AlertWebhook/1.0',
        ];

        if ($secret !== '') {
            $signature = hash_hmac('sha256', $body, $secret);
            $headers['X-BexLogs-Signature'] = "sha256={$signature}";
        }

        $response = Http::timeout(5)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($url);

        if (! $response->successful()) {
            throw new RuntimeException(
                'WebhookDriver: receiver returned HTTP '.$response->status().' — '.mb_substr($response->body(), 0, 200),
            );
        }
    }
}
