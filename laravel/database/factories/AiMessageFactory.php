<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMessage>
 */
class AiMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ai_conversation_id' => AiConversation::factory(),
            'role' => AiMessage::ROLE_USER,
            'content' => fake()->sentence(),
            'tool_calls' => null,
            'tool_call_id' => null,
            'tool_name' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            'model' => null,
        ];
    }

    public function assistant(string $content = 'ok'): self
    {
        return $this->state(fn () => [
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $content,
            'input_tokens' => fake()->numberBetween(50, 500),
            'output_tokens' => fake()->numberBetween(20, 200),
        ]);
    }

    public function tool(string $toolName, string $callId, mixed $result): self
    {
        return $this->state(fn () => [
            'role' => AiMessage::ROLE_TOOL,
            'tool_name' => $toolName,
            'tool_call_id' => $callId,
            'content' => is_string($result) ? $result : json_encode($result),
        ]);
    }
}
