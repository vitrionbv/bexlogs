<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The agent's ownership story end-to-end at the HTTP layer.
 *
 * Alice owns one subscription. Bob owns another. Hitting the chat
 * routes for Bob's subscription as Alice must 403 (route-level walk
 * fails); hitting Bob's existing conversation as Alice must 404 (we
 * don't want to leak the row's existence).
 */
class ChatScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Subscription $aliceSub;

    private Subscription $bobSub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
        $this->aliceSub = $this->makeSubscriptionFor($this->alice);
        $this->bobSub = $this->makeSubscriptionFor($this->bob);
    }

    public function test_alice_cannot_open_bobs_chat_index(): void
    {
        $this->actingAs($this->alice)
            ->get("/logs/subscriptions/{$this->bobSub->id}/chat")
            ->assertForbidden();
    }

    public function test_alice_cannot_start_a_conversation_on_bobs_subscription(): void
    {
        $this->actingAs($this->alice)
            ->postJson("/logs/subscriptions/{$this->bobSub->id}/chat", ['title' => 'sneaky'])
            ->assertForbidden();
    }

    public function test_alice_gets_404_when_opening_a_conversation_owned_by_bob(): void
    {
        // Conversation belongs to Bob; the row's existence must not
        // leak through a 403 (which would tell Alice the id maps to
        // SOMETHING). 404 is the right opaque answer.
        $bobConvo = AiConversation::create([
            'user_id' => $this->bob->id,
            'subscription_id' => $this->bobSub->id,
            'title' => 'bob chat',
        ]);

        $this->actingAs($this->alice)
            ->get("/logs/subscriptions/{$this->bobSub->id}/chat/{$bobConvo->id}")
            ->assertForbidden();
    }

    public function test_alice_gets_404_when_pairing_her_subscription_with_bobs_conversation(): void
    {
        $bobConvo = AiConversation::create([
            'user_id' => $this->bob->id,
            'subscription_id' => $this->bobSub->id,
            'title' => 'bob chat',
        ]);

        // Subscription belongs to Alice, conversation does not — the
        // (user_id, subscription_id) cross-check on the conversation
        // row fails with 404.
        $this->actingAs($this->alice)
            ->get("/logs/subscriptions/{$this->aliceSub->id}/chat/{$bobConvo->id}")
            ->assertNotFound();
    }

    public function test_alice_can_open_her_own_chat_index(): void
    {
        $this->actingAs($this->alice)
            ->get("/logs/subscriptions/{$this->aliceSub->id}/chat")
            ->assertOk();
    }

    private function makeSubscriptionFor(User $owner): Subscription
    {
        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $owner->id,
            'name' => 'Acme '.$owner->id,
        ]);
        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'App '.$owner->id,
        ]);

        return Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Sub '.$owner->id,
            'environment' => 'production',
        ]);
    }
}
