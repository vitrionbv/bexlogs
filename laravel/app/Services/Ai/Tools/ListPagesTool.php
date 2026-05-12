<?php

namespace App\Services\Ai\Tools;

use App\Models\LogMessage;
use App\Models\Page;
use App\Services\Ai\ToolContext;

/**
 * Enumerate the pages (org × application × subscription tuples)
 * inside the current subscription. The LLM uses this when it needs
 * to map a user's natural-language "this thing" to a concrete
 * `page_id` it can pass to `search_logs`.
 */
class ListPagesTool implements Tool
{
    public function name(): string
    {
        return 'list_pages';
    }

    public function description(): string
    {
        return 'List the pages (data sources) inside the current subscription, with row counts and latest log timestamps.';
    }

    public function jsonSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    // no params; emit `{}` not `[]` so OpenRouter doesn't reject the schema.
                    'properties' => new \stdClass,
                ],
            ],
        ];
    }

    public function handle(array $args, ToolContext $ctx): array
    {
        $pages = Page::query()
            ->where('subscription_id', $ctx->subscriptionId)
            ->with(['organization:id,name', 'application:id,name', 'subscription:id,name'])
            ->get();

        return [
            'pages' => $pages->map(fn (Page $p) => [
                'page_id' => $p->id,
                'organization_id' => $p->organization_id,
                'application_id' => $p->application_id,
                // `pages` itself has no `name` column — display name
                // comes from the (org, app, subscription) triple so
                // the LLM can disambiguate when a single subscription
                // owns multiple pages.
                'name' => sprintf(
                    '%s / %s / %s',
                    $p->organization?->name ?? '?',
                    $p->application?->name ?? '?',
                    $p->subscription?->name ?? '?',
                ),
                'latest_log_at' => LogMessage::query()->where('page_id', $p->id)->max('timestamp'),
                'total_logs' => LogMessage::query()->where('page_id', $p->id)->count(),
            ])->all(),
        ];
    }
}
