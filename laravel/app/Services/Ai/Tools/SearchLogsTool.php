<?php

namespace App\Services\Ai\Tools;

use App\Models\LogMessage;
use App\Models\Page;
use App\Services\Ai\LogQueryBuilder;
use App\Services\Ai\ToolContext;

/**
 * search_logs — primary read tool. Returns at most 50 trimmed log rows
 * matching the operator's filter contract (same shape as the Logs UI).
 * Bodies are excerpted to 200 chars so a single response stays well
 * inside any provider context window.
 *
 * Defence-in-depth: every query is hard-scoped to the bound
 * subscription via a join through pages. If the LLM passes a
 * `page_id`, we validate it belongs to the subscription before using
 * it; otherwise we return a structured error so the model can recover.
 */
class SearchLogsTool implements Tool
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public const EXCERPT_CHARS = 200;

    public function __construct(private readonly LogQueryBuilder $builder) {}

    public function name(): string
    {
        return 'search_logs';
    }

    public function description(): string
    {
        return 'Search the current subscription\'s log_messages with the same filter contract the Logs UI uses '
            .'(q / type / action / method / status / date range / jsonFilters). Returns trimmed rows; use get_log to fetch full bodies.';
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
                        'q' => ['type' => 'string', 'description' => 'Free-text needle searched across action, path, method, and JSON bodies (case-insensitive).'],
                        'type' => ['type' => 'string'],
                        'action' => ['type' => 'string'],
                        'method' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'startDate' => ['type' => 'string', 'description' => 'ISO 8601 lower bound on `timestamp`.'],
                        'endDate' => ['type' => 'string', 'description' => 'ISO 8601 upper bound on `timestamp`.'],
                        'jsonFilters' => [
                            'type' => 'array',
                            'description' => 'Each entry matches a JSON field across parameters/request/response columns.',
                            'items' => [
                                'type' => 'object',
                                'required' => ['field', 'value'],
                                'properties' => [
                                    'field' => ['type' => 'string'],
                                    'value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'page_id' => ['type' => 'integer', 'description' => 'Restrict to one page id (must belong to this subscription).'],
                        'orderBy' => ['type' => 'string', 'enum' => ['timestamp', 'type', 'action', 'method', 'status']],
                        'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                        'offset' => ['type' => 'integer', 'minimum' => 0],
                    ],
                ],
            ],
        ];
    }

    public function handle(array $args, ToolContext $ctx): array
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $orderBy = (string) ($args['orderBy'] ?? 'timestamp');
        $direction = (string) ($args['direction'] ?? 'desc');

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

        $this->builder->applySorted(
            $query,
            ToolFilters::fromArgs($args),
            orderBy: $orderBy,
            direction: $direction,
        );

        $total = (clone $query)->count();
        $rows = $query
            ->limit($limit)
            ->offset($offset)
            ->get(['id', 'page_id', 'timestamp', 'type', 'action', 'method', 'path', 'status', 'parameters', 'request', 'response'])
            ->map(fn (LogMessage $row) => [
                'id' => $row->id,
                'page_id' => $row->page_id,
                'timestamp' => (string) $row->timestamp,
                'type' => $row->type,
                'action' => $row->action,
                'method' => $row->method,
                'path' => $row->path,
                'status' => $row->status,
                'parameters_excerpt' => $this->excerpt($row->parameters),
                'request_excerpt' => $this->excerpt($row->request),
                'response_excerpt' => $this->excerpt($row->response),
            ])
            ->all();

        return [
            'total' => $total,
            'returned' => count($rows),
            'limit' => $limit,
            'offset' => $offset,
            'rows' => $rows,
        ];
    }

    private function excerpt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $encoded = is_string($value) ? $value : json_encode($value);
        if (! is_string($encoded)) {
            return null;
        }

        return mb_strimwidth($encoded, 0, self::EXCERPT_CHARS, '…');
    }
}
