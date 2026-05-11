<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Subscription;
use App\Services\Ai\OpenRouterClient;
use App\Services\Ai\ToolContext;
use App\Services\Ai\ToolDispatcher;
use App\Services\Ai\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE chat endpoints for the per-subscription "ask your logs" agent.
 *
 * Routes (all under `/logs/subscriptions/{subscription}/chat`):
 *   GET    /                            -> Logs/Chat.vue (Inertia)
 *   POST   /                            -> create conversation, redirect
 *   GET    /{conversation}              -> Logs/Chat.vue with this convo
 *   POST   /{conversation}/stream       -> SSE response (text/event-stream)
 *   DELETE /{conversation}              -> destroy + redirect
 *
 * Every action calls {@see authorizeSubscriptionAccess()} to walk
 * Subscription -> Application -> Organization -> users.user_id —
 * the same ownership chain `PageController` uses for the Logs UI.
 *
 * Streaming responses run the canonical "tool-use loop": send the
 * conversation to OpenRouter, accumulate the response, dispatch any
 * tool calls, append the tool results, repeat until the model
 * answers in plain text or `tool_iteration_cap` runs out. Tool
 * dispatch is hard-scoped to (user_id, subscription_id) via the
 * ToolContext, which is constructed here and never derived from the
 * LLM's output.
 */
class ChatController extends Controller
{
    /**
     * List the user's conversations for this subscription + render
     * an empty chat panel.
     */
    public function index(Request $request, Subscription $subscription): Response
    {
        $this->authorizeSubscriptionAccess($request, $subscription);

        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'model', 'created_at', 'updated_at']);

        return Inertia::render('Logs/Chat', [
            'subscription' => $this->subscriptionPayload($subscription),
            'conversations' => $conversations,
            'conversation' => null,
            'messages' => [],
            'agentEnabled' => $this->agentEnabled(),
        ]);
    }

    public function show(Request $request, Subscription $subscription, AiConversation $conversation): Response
    {
        $this->authorizeSubscriptionAccess($request, $subscription);
        $this->authorizeConversation($conversation, $subscription, $request);

        return Inertia::render('Logs/Chat', [
            'subscription' => $this->subscriptionPayload($subscription),
            'conversations' => AiConversation::query()
                ->where('user_id', $request->user()->id)
                ->where('subscription_id', $subscription->id)
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get(['id', 'title', 'model', 'created_at', 'updated_at']),
            'conversation' => $conversation->only(['id', 'title', 'model', 'created_at']),
            'messages' => $this->serializeMessages($conversation),
            'agentEnabled' => $this->agentEnabled(),
        ]);
    }

    /**
     * Create an empty conversation. The first user message is sent
     * via the /stream endpoint so we don't have to round-trip
     * through a separate POST. JSON-aware: a fetch-based AJAX
     * caller (the slide-over panel on Logs/Show) gets the new id
     * inline; a full-page submit gets a redirect to /chat/{id}.
     */
    public function start(Request $request, Subscription $subscription): RedirectResponse|JsonResponse
    {
        $this->authorizeSubscriptionAccess($request, $subscription);

        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
        ]);

        $conversation = AiConversation::create([
            'user_id' => $request->user()->id,
            'subscription_id' => $subscription->id,
            'title' => $data['title'] ?? 'New conversation',
            'model' => $data['model'] ?? null,
        ]);

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'conversation' => $conversation->only(['id', 'title', 'model', 'created_at']),
            ]);
        }

        return redirect()->route('logs.chat.show', [
            'subscription' => $subscription->id,
            'conversation' => $conversation->id,
        ]);
    }

    public function destroy(Request $request, Subscription $subscription, AiConversation $conversation): RedirectResponse
    {
        $this->authorizeSubscriptionAccess($request, $subscription);
        $this->authorizeConversation($conversation, $subscription, $request);

        $conversation->delete();

        return redirect()->route('logs.chat.index', ['subscription' => $subscription->id]);
    }

    /**
     * SSE entry point. Persists the user message, then loops:
     *   1. send context to OpenRouter (streaming)
     *   2. emit decoded text deltas as SSE `text` events
     *   3. when the model emits tool_calls, dispatch them, append
     *      tool results to the message list, and continue the loop
     *   4. stop when the model answers in plain text or
     *      `tool_iteration_cap` runs out.
     *
     * Token usage is enforced BEFORE the upstream call so a single
     * runaway user can't burn through the account balance: if the
     * sum of today's (input + output) tokens already exceeds the
     * configured cap, returns 429 directly.
     */
    public function stream(
        Request $request,
        Subscription $subscription,
        AiConversation $conversation,
        OpenRouterClient $client,
        ToolRegistry $registry,
        ToolDispatcher $dispatcher,
    ): StreamedResponse {
        $this->authorizeSubscriptionAccess($request, $subscription);
        $this->authorizeConversation($conversation, $subscription, $request);

        $data = $request->validate([
            'message' => 'required|string|max:8000',
        ]);

        $user = $request->user();

        // Daily token cap — query BEFORE we contact OpenRouter so a
        // saturated user pays exactly zero upstream cost.
        $todayUsed = (int) AiMessage::query()
            ->whereIn('ai_conversation_id', AiConversation::query()
                ->where('user_id', $user->id)
                ->select('id'))
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as total')
            ->value('total');

        $cap = (int) config('ai.daily_token_cap_per_user');
        if ($cap > 0 && $todayUsed >= $cap) {
            abort(429, "Daily token cap of {$cap} reached. Try again tomorrow.");
        }

        $userMessage = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $data['message'],
        ]);

        $ctx = new ToolContext(
            userId: (int) $user->id,
            subscriptionId: (string) $subscription->id,
        );

        // Capture state up-front so callbacks don't reach into the
        // request lifecycle once the response is streaming.
        $iterationCap = (int) config('ai.tool_iteration_cap');
        $contextCap = (int) config('ai.context_message_cap');
        $model = $conversation->model ?: (string) config('ai.default_model');
        $systemPrompt = $this->buildSystemPrompt($subscription);

        $response = new StreamedResponse(function () use (
            $conversation,
            $ctx,
            $iterationCap,
            $contextCap,
            $model,
            $systemPrompt,
            $client,
            $registry,
            $dispatcher,
        ) {
            $sse = function (string $type, array $payload = []) {
                echo 'data: '.json_encode(['type' => $type] + $payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (function_exists('flush')) {
                    @ob_flush();
                    @flush();
                }
            };

            try {
                $messages = $this->buildMessagesForModel($conversation, $systemPrompt, $contextCap);
                $tools = $registry->jsonSchemas();
                $usage = ['input_tokens' => 0, 'output_tokens' => 0];

                for ($iter = 0; $iter < $iterationCap; $iter++) {
                    [$assistantText, $toolCalls, $turnUsage] = $this->streamOneTurn(
                        $client,
                        $messages,
                        $tools,
                        $model,
                        $sse,
                    );
                    $usage['input_tokens'] += $turnUsage['input_tokens'];
                    $usage['output_tokens'] += $turnUsage['output_tokens'];

                    // Persist assistant message (even if it only made
                    // tool calls — we want the `tool_calls` JSON for
                    // replay).
                    $assistantMessage = AiMessage::create([
                        'ai_conversation_id' => $conversation->id,
                        'role' => 'assistant',
                        'content' => $assistantText ?: null,
                        'tool_calls' => $toolCalls ?: null,
                        'input_tokens' => $turnUsage['input_tokens'],
                        'output_tokens' => $turnUsage['output_tokens'],
                        'model' => $model,
                    ]);

                    // Replay into the next model call.
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $assistantText ?? '',
                        'tool_calls' => $toolCalls ?: null,
                    ];

                    if (! $toolCalls) {
                        // Model finished with plain text.
                        break;
                    }

                    foreach ($toolCalls as $call) {
                        $callId = (string) ($call['id'] ?? '');
                        $name = (string) ($call['function']['name'] ?? '');
                        $argsRaw = $call['function']['arguments'] ?? '{}';
                        $args = is_string($argsRaw)
                            ? (json_decode($argsRaw, true) ?: [])
                            : (is_array($argsRaw) ? $argsRaw : []);

                        $sse('tool_call', ['id' => $callId, 'name' => $name, 'args' => $args]);

                        $result = $dispatcher->handle($name, $args, $ctx, $conversation->id);

                        AiMessage::create([
                            'ai_conversation_id' => $conversation->id,
                            'role' => 'tool',
                            'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                            'tool_call_id' => $callId,
                            'tool_name' => $name,
                        ]);

                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $callId,
                            'name' => $name,
                            'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                        ];

                        $sse('tool_result', [
                            'id' => $callId,
                            'name' => $name,
                            'summary' => $this->summariseToolResult($result),
                        ]);
                    }
                }

                // Touch the conversation so the sidebar's
                // updated_at sort surfaces the latest activity.
                $conversation->touch();

                $sse('done', ['usage' => $usage]);
            } catch (\Throwable $e) {
                $sse('error', ['message' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * One pass through OpenRouter. Returns [assistant_text,
     * tool_calls, usage].
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  \Closure  $sse  emit-an-SSE-event callable
     * @return array{0: ?string, 1: ?array<int, array<string, mixed>>, 2: array{input_tokens: int, output_tokens: int}}
     */
    private function streamOneTurn(
        OpenRouterClient $client,
        array $messages,
        array $tools,
        string $model,
        callable $sse,
    ): array {
        $text = '';
        $toolCalls = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];

        foreach ($client->chat($messages, $tools, $model, stream: true) as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }
            if (! empty($chunk['done'])) {
                break;
            }

            // OpenAI/OpenRouter streaming shape: choices[0].delta with
            // either `content` (text) or `tool_calls` (function name +
            // partial argument string we concatenate across chunks).
            $delta = $chunk['choices'][0]['delta'] ?? null;
            if (is_array($delta)) {
                if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                    $text .= $delta['content'];
                    $sse('text', ['delta' => $delta['content']]);
                }
                if (! empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = (int) ($tc['index'] ?? 0);
                        $toolCalls[$idx] ??= [
                            'id' => '',
                            'type' => 'function',
                            'function' => ['name' => '', 'arguments' => ''],
                        ];
                        if (isset($tc['id'])) {
                            $toolCalls[$idx]['id'] = (string) $tc['id'];
                        }
                        if (isset($tc['function']['name'])) {
                            $toolCalls[$idx]['function']['name'] = (string) $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $toolCalls[$idx]['function']['arguments'] .=
                                (string) $tc['function']['arguments'];
                        }
                    }
                }
            }

            if (isset($chunk['usage']) && is_array($chunk['usage'])) {
                $usage['input_tokens'] = (int) ($chunk['usage']['prompt_tokens'] ?? $usage['input_tokens']);
                $usage['output_tokens'] = (int) ($chunk['usage']['completion_tokens'] ?? $usage['output_tokens']);
            }
        }

        return [$text !== '' ? $text : null, $toolCalls ? array_values($toolCalls) : null, $usage];
    }

    /**
     * Construct the OpenRouter `messages` array: system prompt +
     * the last `$contextCap` persisted messages, replayed in role-
     * preserving order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMessagesForModel(
        AiConversation $conversation,
        string $systemPrompt,
        int $contextCap,
    ): array {
        $tail = $conversation->messages()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $contextCap))
            ->get()
            ->reverse()
            ->values();

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($tail as $row) {
            /** @var AiMessage $row */
            $messages[] = $this->messageRowForModel($row);
        }

        return $messages;
    }

    /** @return array<string, mixed> */
    private function messageRowForModel(AiMessage $row): array
    {
        $entry = ['role' => $row->role];
        if ($row->content !== null) {
            $entry['content'] = $row->content;
        }
        if ($row->tool_calls) {
            $entry['tool_calls'] = $row->tool_calls;
        }
        if ($row->tool_call_id !== null) {
            $entry['tool_call_id'] = $row->tool_call_id;
        }
        if ($row->tool_name !== null) {
            $entry['name'] = $row->tool_name;
        }

        return $entry;
    }

    private function buildSystemPrompt(Subscription $subscription): string
    {
        return implode("\n", [
            'You are the bexlogs assistant, helping an operator inspect logs for a specific subscription.',
            sprintf(
                'You are scoped to subscription "%s" (id %s, environment %s). You CANNOT see data from any other subscription; tool results are filtered server-side and that scope cannot be widened.',
                $subscription->name,
                $subscription->id,
                $subscription->environment,
            ),
            'The current time is '.now()->toIso8601String().'.',
            'Prefer calling tools (search_logs, count_logs, get_log, list_pages, aggregate_by) over guessing. Cite specific log ids when you reference rows. Keep answers concise.',
        ]);
    }

    /** @return array<string, mixed> */
    private function summariseToolResult(array $result): array
    {
        if (isset($result['error'])) {
            return ['error' => $result['error']];
        }
        $summary = [];
        foreach (['rows', 'pages', 'buckets'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $summary[$key.'_count'] = count($result[$key]);
            }
        }
        if (isset($result['total'])) {
            $summary['total'] = $result['total'];
        }

        return $summary;
    }

    /** @return array<int, array<string, mixed>> */
    private function serializeMessages(AiConversation $conversation): array
    {
        return $conversation->messages()
            ->get()
            ->map(fn (AiMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'tool_calls' => $m->tool_calls,
                'tool_call_id' => $m->tool_call_id,
                'tool_name' => $m->tool_name,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'environment' => $subscription->environment,
        ];
    }

    /**
     * Surface "is OPENROUTER_API_KEY set?" to the UI so the chat panel
     * can render an "agent disabled" notice instead of letting the user
     * spin up a conversation that will never produce a response.
     */
    private function agentEnabled(): bool
    {
        return (string) config('ai.api_key') !== '';
    }

    /**
     * Subscription -> Application -> Organization -> users.id walk,
     * mirroring `PageController::authorizePageAccess`.
     */
    private function authorizeSubscriptionAccess(Request $request, Subscription $subscription): void
    {
        $owns = Subscription::query()
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('subscriptions.id', $subscription->id)
            ->where('organizations.user_id', $request->user()->id)
            ->exists();
        abort_unless($owns, 403);
    }

    /**
     * A conversation row's (user_id, subscription_id) must match
     * the auth + route bindings exactly. Anything else is treated
     * as a 404 — revealing "this conversation exists but you can't
     * see it" would leak the row's existence.
     */
    private function authorizeConversation(
        AiConversation $conversation,
        Subscription $subscription,
        Request $request,
    ): void {
        $belongs = $conversation->user_id === $request->user()->id
            && (string) $conversation->subscription_id === (string) $subscription->id;
        abort_unless($belongs, 404);
    }
}
