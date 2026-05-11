<?php

namespace Tests\Feature\Ai;

use App\Models\Application;
use App\Models\LogMessage;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\ToolContext;
use App\Services\Ai\ToolDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per-tool scope enforcement, verified end-to-end through the
 * dispatcher. The dispatcher receives a `ToolContext` from the
 * controller — the LLM never gets to construct one — so every tool
 * should refuse to widen the scope no matter what JSON args it's
 * given.
 *
 * Coverage:
 *   - search_logs / count_logs / aggregate_by return only rows from
 *     Alice's subscription, never from Bob's
 *   - cross-subscription `page_id` injection on search_logs returns a
 *     structured `{error: ...}` (not a row leak, not an exception)
 *   - get_log refuses to surface a log id belonging to a foreign
 *     subscription
 */
class ToolDispatcherScopeTest extends TestCase
{
    use RefreshDatabase;

    private ToolDispatcher $dispatcher;

    private User $alice;

    private Subscription $aliceSub;

    private Page $alicePage;

    private Subscription $bobSub;

    private Page $bobPage;

    private LogMessage $bobLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = app(ToolDispatcher::class);

        $this->alice = User::factory()->create();
        [$this->aliceSub, $this->alicePage] = $this->makeSubAndPage($this->alice);
        LogMessage::factory()->count(3)->create([
            'page_id' => $this->alicePage->id,
            'action' => 'Reservation created',
            'type' => 'http',
        ]);

        $bob = User::factory()->create();
        [$this->bobSub, $this->bobPage] = $this->makeSubAndPage($bob);
        $this->bobLog = LogMessage::factory()->create([
            'page_id' => $this->bobPage->id,
            'action' => 'Bob secret',
            'type' => 'http',
        ]);
    }

    public function test_search_logs_returns_only_rows_from_the_context_subscription(): void
    {
        $result = $this->dispatcher->handle('search_logs', [], $this->ctx());

        $this->assertCount(3, $result['rows']);
        foreach ($result['rows'] as $row) {
            $this->assertSame($this->alicePage->id, $row['page_id']);
        }
    }

    public function test_count_logs_does_not_count_foreign_rows(): void
    {
        $result = $this->dispatcher->handle('count_logs', [], $this->ctx());

        $this->assertSame(3, $result['total']);
    }

    public function test_aggregate_by_does_not_leak_foreign_buckets(): void
    {
        $result = $this->dispatcher->handle('aggregate_by', ['field' => 'action'], $this->ctx());

        $values = collect($result['buckets'])->pluck('value')->all();
        $this->assertContains('Reservation created', $values);
        $this->assertNotContains('Bob secret', $values);
    }

    public function test_cross_subscription_page_id_injection_on_search_logs_returns_structured_error(): void
    {
        // page_id belongs to Bob; dispatcher must refuse rather than
        // join across the (page.subscription_id) WHERE clause.
        $result = $this->dispatcher->handle('search_logs', [
            'page_id' => $this->bobPage->id,
        ], $this->ctx());

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not in this subscription', $result['error']);
        $this->assertArrayNotHasKey('rows', $result);
    }

    public function test_get_log_refuses_an_id_outside_the_context_subscription(): void
    {
        $result = $this->dispatcher->handle('get_log', ['id' => $this->bobLog->id], $this->ctx());

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('log', $result);
    }

    public function test_unknown_tool_returns_structured_error(): void
    {
        $result = $this->dispatcher->handle('drop_all_tables', [], $this->ctx());

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('unknown tool', $result['error']);
        $this->assertStringContainsString('drop_all_tables', $result['error']);
    }

    private function ctx(): ToolContext
    {
        return new ToolContext(
            userId: $this->alice->id,
            subscriptionId: $this->aliceSub->id,
        );
    }

    /** @return array{0: Subscription, 1: Page} */
    private function makeSubAndPage(User $owner): array
    {
        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $owner->id,
            'name' => 'Org '.$owner->id,
        ]);
        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'App '.$owner->id,
        ]);
        $sub = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Sub '.$owner->id,
            'environment' => 'production',
        ]);
        $page = Page::create([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $sub->id,
        ]);

        return [$sub, $page];
    }
}
