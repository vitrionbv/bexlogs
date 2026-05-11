<?php

namespace App\Services\Ai;

/**
 * Contract every chat-agent tool must satisfy. The shape mirrors
 * OpenRouter / OpenAI's `tools` array (a `function` with a name,
 * description, and JSON-Schema parameter declaration) plus a single
 * `handle()` entry-point the dispatcher calls.
 */
interface Tool
{
    /**
     * Stable, snake_case identifier the LLM uses to invoke this
     * tool (e.g. `search_logs`). MUST match the schema's `name`.
     */
    public function name(): string;

    /**
     * Plain-English description shown to the LLM. Keep it short:
     * the model uses this to pick BETWEEN tools, not to understand
     * how to call one (that's what `jsonSchema()` is for).
     */
    public function description(): string;

    /**
     * OpenAI/OpenRouter tool schema fragment — a JSON-Schema
     * `parameters` object. The dispatcher wraps it as
     * `{ type: "function", function: { name, description, parameters } }`.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array;

    /**
     * Execute the tool with LLM-supplied args. The implementation
     * MUST inject the `$ctx` scope into every database query so
     * the LLM cannot cross subscription boundaries.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function handle(array $args, ToolContext $ctx): array;
}
