<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Once a user's `sum(input_tokens + output_tokens)` for today exceeds
 * `config('ai.daily_token_cap_per_user')`, /chat/stream must return
 * 429 BEFORE reaching out to OpenRouter — that's the whole point of
 * the cap. We bind a fake client and assert it was never invoked.
 */
class DailyTokenCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_above_daily_cap_returns_429_without_calling_openrouter(): void
    {
        Config::set('ai.api_key', 'test-key');
        Config::set('ai.daily_token_cap_per_user', 100);

        $fake = new FakeOpenRouterClient;
        app()->instance(OpenRouterClient::class, $fake);

        $user = User::factory()->create();
        $sub = $this->subscriptionFor($user);
        $convo = AiConversation::create([
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'title' => 'cap',
        ]);

        // Seed today's usage above the cap (60 + 50 = 110 > 100).
        // `created_at` isn't fillable on AiMessage (the table is
        // append-only and the model sets the timestamp via
        // `useCurrent`), so we go through the query builder to pin
        // the exact created_at the cap check is going to read.
        DB::table('ai_messages')->insert([
            'ai_conversation_id' => $convo->id,
            'role' => 'assistant',
            'content' => 'previous',
            'input_tokens' => 60,
            'output_tokens' => 50,
            'created_at' => Carbon::now()->startOfDay()->addHours(2),
        ]);

        $resp = $this->actingAs($user)->postJson(
            "/logs/subscriptions/{$sub->id}/chat/{$convo->id}/stream",
            ['message' => 'should be capped'],
        );
        $resp->assertStatus(429);

        // Critical: the controller must short-circuit BEFORE the
        // upstream call. If even one chat() call leaked through we'd
        // be burning OpenRouter credits past the cap.
        $this->assertSame([], $fake->calls);
    }

    public function test_yesterdays_usage_does_not_count_against_today(): void
    {
        Config::set('ai.api_key', 'test-key');
        Config::set('ai.daily_token_cap_per_user', 100);

        $fake = new FakeOpenRouterClient;
        $fake->turns = [
            [
                ['choices' => [['delta' => ['content' => 'ok']]]],
                ['usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]],
            ],
        ];
        app()->instance(OpenRouterClient::class, $fake);

        $user = User::factory()->create();
        $sub = $this->subscriptionFor($user);
        $convo = AiConversation::create([
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'title' => 'rollover',
        ]);

        // Far over the cap, but yesterday — the cap window is
        // "today" only. Inserted via the query builder so the
        // `created_at` value isn't trampled by Eloquent's timestamp
        // mass-assignment guard (see the test above).
        DB::table('ai_messages')->insert([
            'ai_conversation_id' => $convo->id,
            'role' => 'assistant',
            'content' => 'yesterday',
            'input_tokens' => 1000,
            'output_tokens' => 1000,
            'created_at' => Carbon::now()->subDay(),
        ]);

        $resp = $this->actingAs($user)->postJson(
            "/logs/subscriptions/{$sub->id}/chat/{$convo->id}/stream",
            ['message' => 'fresh day'],
        );
        $resp->streamedContent();
        $resp->assertOk();
        $this->assertCount(1, $fake->calls);
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
