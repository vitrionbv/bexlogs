<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Happy-path SSE test. The fake {@see FakeOpenRouterClient} feeds the
 * controller two pre-canned model turns:
 *   1. assistant emits a tool_call (list_pages, no args)
 *   2. assistant emits a plain-text answer
 *
 * The controller should:
 *   - stream `tool_call`, `tool_result`, `text`, `done` SSE frames in
 *     that order (text frames may also appear in turn 1 if the model
 *     interleaves them — none in this fake to keep the order pin
 *     deterministic),
 *   - persist a user message, two assistant messages (one per turn),
 *     and one tool message,
 *   - sum input/output token counts across turns and emit them on the
 *     final `done` frame.
 */
class ChatStreamingTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_emits_expected_sse_event_order_and_persists_assistant_tokens(): void
    {
        // The /chat/stream endpoint guards on a non-empty
        // OPENROUTER_API_KEY in production via the index/show prop, but
        // the streaming controller itself only consults the client we
        // bind below — keep the config explicit so future config-driven
        // gates don't surprise the test.
        Config::set('ai.api_key', 'test-key');
        Config::set('ai.tool_iteration_cap', 6);

        $fake = new FakeOpenRouterClient;
        $fake->turns = [
            // Turn 1 — assistant decides to call list_pages.
            [
                [
                    'choices' => [[
                        'delta' => [
                            'tool_calls' => [[
                                'index' => 0,
                                'id' => 'call_1',
                                'function' => ['name' => 'list_pages', 'arguments' => '{}'],
                            ]],
                        ],
                    ]],
                ],
                ['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]],
            ],
            // Turn 2 — assistant produces text.
            [
                ['choices' => [['delta' => ['content' => 'Hello, ']]]],
                ['choices' => [['delta' => ['content' => 'world!']]]],
                ['usage' => ['prompt_tokens' => 20, 'completion_tokens' => 6]],
            ],
        ];
        app()->instance(OpenRouterClient::class, $fake);

        $user = User::factory()->create();
        $sub = $this->subscriptionFor($user);
        $convo = AiConversation::create([
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'title' => 'streaming',
        ]);

        $response = $this->actingAs($user)->postJson(
            "/logs/subscriptions/{$sub->id}/chat/{$convo->id}/stream",
            ['message' => 'List my pages']
        );

        $response->assertOk();
        // Symfony appends `; charset=utf-8` to text/* responses by
        // default — keep the assertion content-type-prefixed.
        $this->assertStringStartsWith(
            'text/event-stream',
            (string) $response->headers->get('Content-Type'),
        );

        $body = $response->streamedContent();

        // Order: the tool_call must precede its tool_result, which must
        // precede the text frames produced by turn 2.
        $toolCallAt = strpos($body, '"type":"tool_call"');
        $toolResultAt = strpos($body, '"type":"tool_result"');
        $textAt = strpos($body, '"type":"text"');
        $doneAt = strpos($body, '"type":"done"');
        $this->assertNotFalse($toolCallAt, 'tool_call frame missing');
        $this->assertNotFalse($toolResultAt, 'tool_result frame missing');
        $this->assertNotFalse($textAt, 'text frame missing');
        $this->assertNotFalse($doneAt, 'done frame missing');
        $this->assertLessThan($toolResultAt, $toolCallAt);
        $this->assertLessThan($textAt, $toolResultAt);
        $this->assertLessThan($doneAt, $textAt);

        // Tool name made it into the call frame.
        $this->assertStringContainsString('"name":"list_pages"', $body);
        // Hello/world deltas concatenated into the assistant content.
        $this->assertStringContainsString('"delta":"Hello, "', $body);
        $this->assertStringContainsString('"delta":"world!"', $body);

        // Aggregate usage emitted on the done frame.
        $this->assertStringContainsString('"input_tokens":30', $body);
        $this->assertStringContainsString('"output_tokens":10', $body);

        // One user, two assistants (one per turn), one tool message.
        $messages = AiMessage::where('ai_conversation_id', $convo->id)->orderBy('id')->get();
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('tool', $messages[2]->role);
        $this->assertSame('list_pages', $messages[2]->tool_name);
        $this->assertSame('assistant', $messages[3]->role);
        $this->assertSame('Hello, world!', $messages[3]->content);

        // Token counts attributed to each assistant turn.
        $this->assertSame(10, (int) $messages[1]->input_tokens);
        $this->assertSame(4, (int) $messages[1]->output_tokens);
        $this->assertSame(20, (int) $messages[3]->input_tokens);
        $this->assertSame(6, (int) $messages[3]->output_tokens);

        // The fake recorded two upstream round-trips with the same model.
        $this->assertCount(2, $fake->calls);
    }

    private function subscriptionFor(User $user): Subscription
    {
        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $user->id,
            'name' => 'Acme',
        ]);
        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'App',
        ]);

        return Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Sub',
            'environment' => 'production',
        ]);
    }
}
