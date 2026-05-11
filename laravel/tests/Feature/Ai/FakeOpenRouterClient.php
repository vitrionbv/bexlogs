<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\OpenRouterClient;
use Generator;

/**
 * Test double for {@see OpenRouterClient}. The real client streams
 * SSE chunks decoded from OpenRouter's HTTP response; tests bind an
 * instance of this class via `app()->instance(OpenRouterClient::class,
 * $fake)` and pre-load `$turns` with the chunks each model round-trip
 * should yield.
 *
 * One element of `$turns` per round-trip; each element is an ordered
 * list of decoded chunk arrays. The controller exits its loop when
 * the generator returns without surfacing tool_calls, so the last
 * turn typically yields a text-only delta + a usage chunk.
 */
class FakeOpenRouterClient extends OpenRouterClient
{
    /** @var list<list<array<string, mixed>>> */
    public array $turns = [];

    /** @var list<array{messages: array<int, mixed>, tools: array<int, mixed>, model: ?string}> */
    public array $calls = [];

    private int $turnIndex = 0;

    public function chatStream(array $messages, array $tools = [], ?string $model = null): Generator
    {
        $this->calls[] = [
            'messages' => $messages,
            'tools' => $tools,
            'model' => $model,
        ];

        $turn = $this->turns[$this->turnIndex++] ?? [];
        foreach ($turn as $chunk) {
            yield $chunk;
        }
    }
}
