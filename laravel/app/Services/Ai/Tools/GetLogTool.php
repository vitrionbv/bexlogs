<?php

namespace App\Services\Ai\Tools;

use App\Models\LogMessage;
use App\Services\Ai\ToolContext;

/**
 * get_log — full row for one log_messages id, scoped to the current
 * subscription. The model uses this after search_logs surfaces an
 * interesting row id and it wants the un-truncated bodies.
 */
class GetLogTool implements Tool
{
    public function name(): string
    {
        return 'get_log';
    }

    public function description(): string
    {
        return 'Fetch the full log_messages row (parameters/request/response un-truncated) for one id in the current subscription.';
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
                    'required' => ['id'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                    ],
                ],
            ],
        ];
    }

    public function handle(array $args, ToolContext $ctx): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return ['error' => 'id is required and must be a positive integer.'];
        }

        $row = LogMessage::query()
            ->where('log_messages.id', $id)
            ->whereExists(function ($q) use ($ctx) {
                $q->selectRaw('1')
                    ->from('pages')
                    ->whereColumn('pages.id', 'log_messages.page_id')
                    ->where('pages.subscription_id', $ctx->subscriptionId);
            })
            ->first();

        if ($row === null) {
            return ['error' => "log id {$id} not found in this subscription."];
        }

        return [
            'id' => $row->id,
            'page_id' => $row->page_id,
            'timestamp' => (string) $row->timestamp,
            'type' => $row->type,
            'action' => $row->action,
            'method' => $row->method,
            'path' => $row->path,
            'status' => $row->status,
            'parameters' => $row->parameters,
            'request' => $row->request,
            'response' => $row->response,
        ];
    }
}
