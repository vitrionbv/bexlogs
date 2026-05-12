<?php

namespace App\Services\Ai\Tools;

use App\Models\LogMessage;
use App\Models\Page;
use App\Services\Ai\LogQueryBuilder;
use App\Services\Ai\ToolContext;

/**
 * count_logs — sibling of search_logs that only returns `{ total }`.
 * Lets the model answer "how many" questions without burning context
 * on row bodies it doesn't need.
 */
class CountLogsTool implements Tool
{
    public function __construct(private readonly LogQueryBuilder $builder) {}

    public function name(): string
    {
        return 'count_logs';
    }

    public function description(): string
    {
        return 'Count log_messages matching the given filters in the current subscription. Same filter shape as search_logs.';
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
                    'properties' => [
                        'q' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'action' => ['type' => 'string'],
                        'method' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'startDate' => ['type' => 'string'],
                        'endDate' => ['type' => 'string'],
                        'jsonFilters' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['field', 'value'],
                                'properties' => [
                                    'field' => ['type' => 'string'],
                                    'value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'page_id' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    public function handle(array $args, ToolContext $ctx): array
    {
        $query = LogMessage::query()
            ->whereIn('log_messages.page_id', Page::query()
                ->where('pages.subscription_id', $ctx->subscriptionId)
                ->select('pages.id'));

        if (isset($args['page_id'])) {
            $pageId = (int) $args['page_id'];
            $belongs = Page::where('id', $pageId)
                ->where('subscription_id', $ctx->subscriptionId)
                ->exists();
            if (! $belongs) {
                return ['error' => "page_id {$pageId} is not in this subscription."];
            }
            $query->where('log_messages.page_id', $pageId);
        }

        $this->builder->applyFilters($query, ToolFilters::fromArgs($args));

        return ['total' => $query->count()];
    }
}
