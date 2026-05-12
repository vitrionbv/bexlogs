<?php

namespace App\Services\Ai;

use Generator;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Thin OpenRouter ( https://openrouter.ai/docs/api-reference/overview )
 * wrapper. OpenRouter speaks the OpenAI /v1/chat/completions schema
 * including streaming and tool-use, so a single tiny client covers every
 * model the agent might pick.
 *
 * Two surfaces:
 *   - chat()        — non-streaming. Returns the decoded JSON body.
 *   - chatStream()  — streaming. Returns a Generator that yields each
 *                     decoded SSE chunk (already JSON-decoded, with the
 *                     "data: " prefix and the terminating [DONE] line
 *                     stripped). Caller iterates and forwards deltas.
 *
 * Streaming is implemented via Guzzle's `RequestOptions::STREAM => true`
 * (PSR-7 stream body) so we never buffer the entire upstream response
 * in memory and the bytes hit the SSE response as soon as the upstream
 * provider emits them. We deliberately don't use Laravel's HTTP client
 * for the streaming path because its facade-level wrappers aggressively
 * buffer the response body.
 */
class OpenRouterClient
{
    /** Lazily-built Guzzle client used for streaming requests. */
    private ?GuzzleClient $guzzle = null;

    /**
     * Non-streaming chat completion.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function chat(array $messages, array $tools = [], ?string $model = null): array
    {
        $payload = $this->buildPayload($messages, $tools, $model, stream: false);

        $response = Http::withHeaders($this->headers())
            ->timeout((int) config('ai.request_timeout_seconds'))
            ->baseUrl((string) config('ai.base_url'))
            ->acceptJson()
            ->post('/chat/completions', $payload)
            ->throw();

        return $response->json();
    }

    /**
     * Streaming chat completion. Yields one decoded chunk per SSE event,
     * each shaped like the upstream provider's standard
     * `chat.completion.chunk` object. Skips the terminating `[DONE]`
     * marker. Yields nothing for keep-alive comments / empty data lines.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(array $messages, array $tools = [], ?string $model = null): Generator
    {
        $payload = $this->buildPayload($messages, $tools, $model, stream: true);

        $client = $this->guzzle ??= new GuzzleClient([
            'base_uri' => rtrim((string) config('ai.base_url'), '/').'/',
            'timeout' => (int) config('ai.request_timeout_seconds'),
        ]);

        $response = $client->post('chat/completions', [
            RequestOptions::HEADERS => array_merge($this->headers(), ['Accept' => 'text/event-stream']),
            RequestOptions::JSON => $payload,
            RequestOptions::STREAM => true,
        ]);

        if ($response->getStatusCode() >= 400) {
            $body = (string) $response->getBody();
            throw new RuntimeException("OpenRouter HTTP {$response->getStatusCode()}: {$body}");
        }

        yield from $this->parseSseStream($response->getBody());
    }

    /**
     * Pull SSE frames out of a PSR-7 stream and yield each `data:` line's
     * decoded JSON payload. Buffers across chunks because Guzzle's read()
     * doesn't honour line boundaries.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function parseSseStream(StreamInterface $body): Generator
    {
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                // Some PSR-7 implementations return '' on a partial read
                // before EOF; nothing to do but keep looping.
                continue;
            }
            $buffer .= $chunk;

            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                $line = rtrim($line, "\r");
                if ($line === '' || str_starts_with($line, ':')) {
                    // SSE event separator or keep-alive comment.
                    continue;
                }
                if (! str_starts_with($line, 'data:')) {
                    // OpenRouter only emits `data:` lines on the chat
                    // endpoint. Anything else is upstream noise we can
                    // safely drop on the floor.
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }

                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    private function buildPayload(array $messages, array $tools, ?string $model, bool $stream): array
    {
        $payload = [
            'model' => $model ?: (string) config('ai.default_model'),
            'messages' => $messages,
            'max_tokens' => (int) config('ai.max_output_tokens'),
            'stream' => $stream,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $key = (string) config('ai.api_key');
        if ($key === '') {
            // The controller layer also short-circuits this; a clear
            // exception here makes debugging local dev (forgot to set
            // the key) painless instead of returning an opaque 401.
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        return [
            'Authorization' => 'Bearer '.$key,
            'HTTP-Referer' => (string) (config('ai.app_url') ?? ''),
            'X-Title' => (string) (config('ai.app_title') ?? ''),
            'Content-Type' => 'application/json',
        ];
    }
}
