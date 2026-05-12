<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\ToolContext;

/**
 * Contract every agent tool implements. The dispatcher looks up tools
 * by `name()` and feeds them `(decoded args, $ctx)`.
 *
 * `jsonSchema()` returns the OpenAI/OpenRouter `tools` entry for this
 * tool — the canonical shape is:
 *   {type: "function", function: {name, description, parameters: {…}}}
 */
interface Tool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> */
    public function jsonSchema(): array;

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function handle(array $args, ToolContext $ctx): array;
}
