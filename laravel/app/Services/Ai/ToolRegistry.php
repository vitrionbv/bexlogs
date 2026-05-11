<?php

namespace App\Services\Ai;

use App\Services\Ai\Tools\AggregateByTool;
use App\Services\Ai\Tools\CountLogsTool;
use App\Services\Ai\Tools\GetLogTool;
use App\Services\Ai\Tools\ListPagesTool;
use App\Services\Ai\Tools\SearchLogsTool;
use App\Services\Ai\Tools\Tool;

/**
 * Single source of truth for the agent's tool catalogue. Resolved
 * through the container so the constructor wires in the LogQueryBuilder
 * dependencies; called from both the ChatController (to advertise
 * tools to OpenRouter) and the ToolDispatcher (to run them).
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $byName;

    public function __construct(
        SearchLogsTool $search,
        CountLogsTool $count,
        GetLogTool $get,
        ListPagesTool $list,
        AggregateByTool $agg,
    ) {
        $this->byName = [];
        foreach ([$search, $count, $get, $list, $agg] as $tool) {
            $this->byName[$tool->name()] = $tool;
        }
    }

    /** @return array<string, Tool> */
    public function all(): array
    {
        return $this->byName;
    }

    public function get(string $name): ?Tool
    {
        return $this->byName[$name] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSchemas(): array
    {
        return array_values(array_map(fn (Tool $t) => $t->jsonSchema(), $this->byName));
    }
}
