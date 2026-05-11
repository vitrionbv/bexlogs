<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_messages — every turn of an AI conversation: user prompts,
 * assistant deltas (collapsed into one row at end-of-stream), tool
 * calls the assistant emitted, and the JSON results we fed back.
 *
 * Roles map to OpenAI/OpenRouter chat-completion roles:
 *   - user      operator typed a prompt
 *   - assistant model produced text and/or tool_calls
 *   - tool      tool dispatcher returned a result for a previous
 *               tool_call_id
 *   - system    pinned scope/context preamble (rarely persisted; we
 *               build it on the fly per request, but the column
 *               supports it for replay debugging)
 *
 * `tool_calls` carries the assistant's emitted function-calls (OpenAI
 * shape: `[{id, type:"function", function:{name, arguments}}]`).
 * `tool_call_id` + `tool_name` only on `role = tool` rows.
 *
 * Token columns are nullable on user/tool rows because OpenRouter only
 * reports usage on the assistant side. They're indexed indirectly
 * through (user_id, created_at) on the parent — the daily token cap
 * query joins ai_conversations.user_id and sums these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->string('tool_name')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['ai_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
