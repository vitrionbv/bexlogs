<?php

namespace Tests\Feature\Api;

use App\Models\Application;
use App\Models\LogMessage;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * LogMessage is the largest-volume table by far, so unbounded
 * collection responses would happily eat all the worker memory. The
 * #[ApiResource] declaration pins `paginationItemsPerPage: 30`; this
 * test proves the pagination wiring is actually in effect (page 1
 * returns 30 rows, page 2 returns the next chunk) AND that the
 * org-scoping extension composes with paging (page 2 still only
 * shows the authenticated user's rows).
 */
class LogMessagePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagination_returns_next_thirty_rows_on_page_two(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->for($user)->create();
        $app = Application::factory()->create(['organization_id' => $org->id]);
        $sub = Subscription::factory()->create(['application_id' => $app->id]);
        $page = Page::factory()->create([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $sub->id,
        ]);

        // 45 distinct rows -> 30 on page 1, 15 on page 2.
        // The unique index on (page_id, timestamp, type, action, method,
        // status) means we need to vary `action` per row, otherwise the
        // factory's randomised columns can collide.
        for ($i = 0; $i < 45; $i++) {
            LogMessage::factory()->create([
                'page_id' => $page->id,
                'action' => 'act-'.$i,
                'timestamp' => now()->subSeconds($i)->toIso8601String(),
            ]);
        }

        Sanctum::actingAs($user, ['read']);

        $page1 = $this
            ->getJson('/api/log-messages?page=1', ['Accept' => 'application/ld+json'])
            ->assertOk();
        $this->assertCount(30, $page1->json('member'));
        $this->assertSame(45, $page1->json('totalItems'));

        $page2 = $this
            ->getJson('/api/log-messages?page=2', ['Accept' => 'application/ld+json'])
            ->assertOk();
        $this->assertCount(15, $page2->json('member'));
        $this->assertSame(45, $page2->json('totalItems'));

        // The two pages must not overlap — different ids in each.
        $page1Ids = collect($page1->json('member'))->pluck('id')->all();
        $page2Ids = collect($page2->json('member'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids));
    }

    public function test_log_message_pagination_respects_org_scope(): void
    {
        // Two users with their own (page, log) graphs. Both insert
        // enough rows to fill page 2, but each user should only ever
        // see their own — even when paging.
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $alicePage = $this->seedPage($alice);
        $bobPage = $this->seedPage($bob);

        for ($i = 0; $i < 35; $i++) {
            LogMessage::factory()->create([
                'page_id' => $alicePage->id,
                'action' => 'a-'.$i,
                'timestamp' => now()->subSeconds($i)->toIso8601String(),
            ]);
            LogMessage::factory()->create([
                'page_id' => $bobPage->id,
                'action' => 'b-'.$i,
                'timestamp' => now()->subSeconds($i)->toIso8601String(),
            ]);
        }

        Sanctum::actingAs($alice, ['read']);

        $response = $this
            ->getJson('/api/log-messages?page=2', ['Accept' => 'application/ld+json'])
            ->assertOk();
        $this->assertCount(5, $response->json('member'));
        $this->assertSame(35, $response->json('totalItems'));

        // None of Bob's rows must leak into Alice's page 2.
        $actions = collect($response->json('member'))->pluck('action')->all();
        foreach ($actions as $action) {
            $this->assertStringStartsWith('a-', $action);
        }
    }

    private function seedPage(User $owner): Page
    {
        $org = Organization::factory()->for($owner)->create();
        $app = Application::factory()->create(['organization_id' => $org->id]);
        $sub = Subscription::factory()->create(['application_id' => $app->id]);

        return Page::factory()->create([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $sub->id,
        ]);
    }
}
