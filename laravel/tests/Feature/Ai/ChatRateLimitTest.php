<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/chat/stream` is mounted with the `throttle:30,1` middleware so a
 * single misbehaving tab cannot outrun the daily token cap on its
 * own. This test confirms the 31st POST inside a minute is rejected
 * with a 429 BEFORE the controller (and therefore OpenRouter) sees it.
 */
class ChatRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_thirty_first_stream_request_in_a_minute_returns_429(): void
    {
        Config::set('ai.api_key', 'test-key');
        Config::set('ai.daily_token_cap_per_user', 0); // disable cap so rate limiter is the only gate

        $fake = new FakeOpenRouterClient;
        // Keep the upstream cheap — every accepted request just emits
        // an immediate done frame so the 30 successful turns don't
        // blow the test budget.
        for ($i = 0; $i < 31; $i++) {
            $fake->turns[$i] = [
                ['usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]],
            ];
        }
        app()->instance(OpenRouterClient::class, $fake);

        $user = User::factory()->create();
        $sub = $this->subscriptionFor($user);
        $convo = AiConversation::create([
            'user_id' => $user->id,
            'subscription_id' => $sub->id,
            'title' => 'rate-limit',
        ]);

        // Hammer the same endpoint 30 times — all should succeed.
        RateLimiter::clear($this->limiterKey($user));
        for ($i = 0; $i < 30; $i++) {
            $resp = $this->actingAs($user)->postJson(
                "/logs/subscriptions/{$sub->id}/chat/{$convo->id}/stream",
                ['message' => "ping {$i}"],
            );
            // streamedContent() runs the closure so we don't hold the
            // output buffer open between requests.
            $resp->streamedContent();
            $resp->assertOk();
        }

        // The 31st must hit the throttle before reaching the controller.
        $blocked = $this->actingAs($user)->postJson(
            "/logs/subscriptions/{$sub->id}/chat/{$convo->id}/stream",
            ['message' => 'over the line'],
        );
        $blocked->assertStatus(429);
    }

    private function limiterKey(User $user): string
    {
        // The `throttle:30,1` middleware (without a custom name) keys
        // off the user id; we only need to clear it to keep test order
        // independent.
        return (string) $user->id;
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
