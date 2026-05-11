<?php

namespace App\Models;

use Database\Factories\AiMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per chat-completion message: user prompts, assistant
 * deltas (collapsed at end-of-stream), tool_calls the assistant emitted,
 * and the JSON results we fed back. Roles match the OpenAI/OpenRouter
 * chat schema (`user`, `assistant`, `tool`, `system`).
 */
#[Fillable([
    'ai_conversation_id',
    'role',
    'content',
    'tool_calls',
    'tool_call_id',
    'tool_name',
    'input_tokens',
    'output_tokens',
    'model',
])]
class AiMessage extends Model
{
    /** @use HasFactory<AiMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    public const ROLE_SYSTEM = 'system';

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
