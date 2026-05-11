<?php

namespace App\Services\Ai\Tools;

use App\Models\LogMessage;
use App\Models\Page;
use App\Services\Ai\LogQueryBuilder;
use App\Services\Ai\ToolContext;
use Illuminate\Support\Facades\DB;

/**
 * aggregate_by — distinct-value top-N over one column (type, action,
 * method, status, path) optionally narrowed by the same filter shape
 * search_logs accepts. Lets the model answer "what are the most
 * common 4xx actions?" in one round-trip.
 */
class AggregateByTool implements Tool
{
    public const ALLOWED_FIELDS = ['type', 'action', 'method', 'status', 'path'];

    public const DEFAULT_TOP_N = 10;

    public const MAX_TOP_N = 20;

    public function __construct(private readonly LogQueryBuilder $builder) {}

    public function name(): string
    {
        return 'aggregate_by';
    }

    public function description(): string
    {
        return 'Top-N distinct values of one column (type/action/method/status/path) within the current subscription, '
            .'optionally narrowed by the standard filter shape.';
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
                    'required' => ['field'],
                    'properties' => [
                        'field' => ['type' => 'string', 'enum' => self::ALLOWED_FIELDS],
                        'top_n' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_TOP_N],
                        'filters' => [
                            'type' => 'object',
                            'description' => 'Same filter shape search_logs accepts (q, type, action, method, status, startDate, endDate, jsonFilters).',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function handle(array $args, ToolContext $ctx): array
    {
        $field = (string) ($args['field'] ?? '');
        if (! in_array($field, self::ALLOWED_FIELDS, true)) {
            return ['error' => 'field must be one of: '.implode(', ', self::ALLOWED_FIELDS)];
        }

        $topN = min(self::MAX_TOP_N, max(1, (int) ($args['top_n'] ?? self::DEFAULT_TOP_N)));
        $filters = is_array($args['filters'] ?? null) ? ToolFilters::fromArgs($args['filters']) : [];

        $query = LogMessage::query()
            ->whereIn('log_messages.page_id', Page::query()
                ->where('pages.subscription_id', $ctx->subscriptionId)
                ->select('pages.id'));

        $this->builder->applyFilters($query, $filters);

        $rows = $query
            ->select($field, DB::raw('COUNT(*) as c'))
            ->groupBy($field)
            ->orderByDesc('c')
            ->limit($topN)
            ->get();

        return [
            'field' => $field,
            'buckets' => $rows->map(fn ($r) => [
                'value' => $r->{$field},
                'count' => (int) $r->c,
            ])->all(),
        ];
    }
}
