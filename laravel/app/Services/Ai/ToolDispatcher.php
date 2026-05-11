<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validates + executes one tool call. The LLM emits a `tool_call`
 * with `(name, arguments)`; the dispatcher resolves the tool, runs
 * it inside a try/catch, and returns either the tool's JSON-shaped
 * result or `{error: "..."}` on validation/runtime failure.
 *
 * Every dispatch logs a structured `ai.tool` line with subscription
 * + conversation + duration + row-count for ops observability.
 */
class ToolDispatcher
{
    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * @param  array<string, mixed>|string|null  $args  raw tool arguments — accepts the JSON string the
     *                                                  provider emits or an already-decoded array.
     * @return array<string, mixed>
     */
    public function handle(string $name, array|string|null $args, ToolContext $ctx): array
    {
        $tool = $this->registry->get($name);
        if ($tool === null) {
            $this->log($name, $ctx, 0, 0, 'unknown_tool');

            return ['error' => "unknown tool '{$name}'."];
        }

        $decoded = $this->decode($args);
        if ($decoded === null) {
            $this->log($name, $ctx, 0, 0, 'invalid_arguments_json');

            return ['error' => "tool '{$name}': arguments must be valid JSON or an array."];
        }

        $started = microtime(true);
        try {
            $result = $tool->handle($decoded, $ctx);
        } catch (Throwable $e) {
            $this->log($name, $ctx, (int) ((microtime(true) - $started) * 1000), 0, 'exception:'.$e::class);

            return ['error' => "tool '{$name}' threw: ".$e->getMessage()];
        }

        $rowsReturned = is_array($result['rows'] ?? null) ? count($result['rows']) : null;
        $this->log(
            tool: $name,
            ctx: $ctx,
            durationMs: (int) ((microtime(true) - $started) * 1000),
            rowsReturned: $rowsReturned ?? (isset($result['total']) ? (int) $result['total'] : 0),
            note: isset($result['error']) ? 'tool_error' : 'ok',
        );

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function decode(array|string|null $args): ?array
    {
        if ($args === null || $args === '') {
            return [];
        }
        if (is_array($args)) {
            return $args;
        }
        $decoded = json_decode($args, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function log(string $tool, ToolContext $ctx, int $durationMs, int $rowsReturned, string $note): void
    {
        Log::info('ai.tool', [
            'tool' => $tool,
            'user_id' => $ctx->userId,
            'subscription_id' => $ctx->subscriptionId,
            'conversation_id' => $ctx->conversationId,
            'duration_ms' => $durationMs,
            'rows_returned' => $rowsReturned,
            'note' => $note,
        ]);
    }
}
