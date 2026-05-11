<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\SavedQuery;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F16 Cmd-K search endpoint. The palette debounces 150ms client-side,
 * so the controller is hit ~6 times per second on a fast typer. The
 * tests below assert:
 *
 *   - matches across name + id + environment
 *   - per-user scoping (no cross-tenant leakage)
 *   - empty query returns empty groups (not a 400)
 *   - saved-queries group is omitted gracefully when the model
 *     doesn't exist on the current branch
 */
class CmdkSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->other = User::factory()->create();

        $this->sub = $this->makeSub($this->user, name: 'Acme Production', env: 'production');
        $this->makeSub($this->user, name: 'Acme Staging', env: 'staging');
        $this->makeSub($this->other, name: 'Acme Foreign', env: 'production');
    }

    private function makeSub(User $owner, string $name, string $env): Subscription
    {
        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $owner->id,
            'name' => "Org for {$name}",
        ]);
        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => "App for {$name}",
        ]);

        return Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => $name,
            'environment' => $env,
        ]);
    }

    public function test_empty_query_returns_empty_groups(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('api.search', ['q' => '']));

        $response->assertOk();
        $response->assertExactJson([
            'subscriptions' => [],
            'scrape_jobs' => [],
            'saved_queries' => [],
        ]);
    }

    public function test_name_match_returns_subscription_entries(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('api.search', ['q' => 'acme']));

        $response->assertOk();
        $payload = $response->json();

        // Two owned subs match, foreign sub does not.
        $this->assertCount(2, $payload['subscriptions']);
        $names = array_column($payload['subscriptions'], 'label');
        $this->assertContains('Acme Production', $names);
        $this->assertContains('Acme Staging', $names);
        $this->assertNotContains('Acme Foreign', $names);
    }

    public function test_environment_keyword_matches_subscription(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson(route('api.search', ['q' => 'staging']));

        $payload = $response->json();
        $this->assertSame('Acme Staging', $payload['subscriptions'][0]['label']);
    }

    public function test_scrape_jobs_surface_in_results(): void
    {
        $session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);
        $job = ScrapeJob::create([
            'subscription_id' => $this->sub->id,
            'bex_session_id' => $session->id,
            'status' => ScrapeJob::STATUS_QUEUED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.search', ['q' => (string) $job->id]));

        $payload = $response->json();
        $this->assertGreaterThanOrEqual(1, count($payload['scrape_jobs']));
        $this->assertSame((string) $job->id, $payload['scrape_jobs'][0]['id']);
    }

    public function test_saved_queries_group_omitted_when_model_missing(): void
    {
        // Guarantee the conditional class_exists branch takes the
        // fallback path: the SavedQuery class lives on Agent 2's
        // branch and isn't merged into this branch's history.
        $this->assertFalse(class_exists(SavedQuery::class));

        $response = $this->actingAs($this->user)
            ->getJson(route('api.search', ['q' => 'anything']));

        $response->assertOk();
        $this->assertSame([], $response->json('saved_queries'));
    }

    public function test_results_capped_per_group(): void
    {
        // Create 15 matching subs; the controller caps at 10 per group.
        for ($i = 0; $i < 15; $i++) {
            $this->makeSub($this->user, name: "ZZZ {$i}", env: 'production');
        }

        $response = $this->actingAs($this->user)
            ->getJson(route('api.search', ['q' => 'zzz']));

        $this->assertCount(10, $response->json('subscriptions'));
    }
}
