<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LogMessage;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LogSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardLogStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Page $page;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'Dash Log Stats Org',
        ]);

        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Dash Log Stats App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Primary Sub',
            'environment' => 'production',
        ]);

        $this->page = Page::create([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $this->subscription->id,
        ]);
    }

    public function test_dashboard_includes_log_analytics_props(): void
    {
        Carbon::setTestNow('2026-05-22T12:00:00Z');

        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-05-22T10:00:00Z',
            'type' => 'webhook',
            'action' => 'reservation.created',
        ]);

        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('logsPerDay', 30)
            ->has('topSubscriptionsToday', 1)
            ->where('topSubscriptionsToday.0.subscription_id', $this->subscription->id)
            ->where('topSubscriptionsToday.0.total_today', 1)
            ->where('topSubscriptionsToday.0.top_entries.0.action', 'reservation.created'));

        Carbon::setTestNow();
    }

    public function test_logs_per_day_is_user_scoped_and_zero_filled(): void
    {
        Carbon::setTestNow('2026-05-22T12:00:00Z');

        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-05-20T08:00:00Z',
        ]);
        LogMessage::factory()->count(2)->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-05-21T08:00:00Z',
        ]);

        $otherUser = User::factory()->create();
        $otherPage = Page::factory()->create();
        LogMessage::factory()->count(5)->create(['page_id' => $otherPage->id]);

        $series = LogSummary::logsPerDayForUser($this->user, 30);

        $this->assertCount(30, $series);
        $this->assertSame(0, collect($series)->firstWhere('day', '2026-05-19')['count']);
        $this->assertSame(1, collect($series)->firstWhere('day', '2026-05-20')['count']);
        $this->assertSame(2, collect($series)->firstWhere('day', '2026-05-21')['count']);
        $this->assertSame(0, collect($series)->firstWhere('day', '2026-05-22')['count']);

        Carbon::setTestNow();
    }

    public function test_top_subscriptions_today_groups_patterns_per_subscription(): void
    {
        Carbon::setTestNow('2026-05-22T15:00:00Z');

        LogMessage::factory()->count(5)->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-05-22T09:00:00Z',
            'type' => 'webhook',
            'action' => 'reservation.created',
        ]);
        LogMessage::factory()->count(2)->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-05-22T10:00:00Z',
            'type' => 'http',
            'action' => 'GET /api/v1/reservations',
        ]);
        LogMessage::factory()->create([
            'page_id' => $this->page->id,
            'timestamp' => '2026-05-21T23:59:59Z',
            'type' => 'webhook',
            'action' => 'reservation.created',
        ]);

        $rows = LogSummary::topSubscriptionsTodayForUser($this->user);

        $this->assertCount(1, $rows);
        $this->assertSame(7, $rows[0]['total_today']);
        $this->assertSame('reservation.created', $rows[0]['top_entries'][0]['action']);
        $this->assertSame(5, $rows[0]['top_entries'][0]['count']);
        $this->assertSame('GET /api/v1/reservations', $rows[0]['top_entries'][1]['action']);
        $this->assertSame(2, $rows[0]['top_entries'][1]['count']);

        Carbon::setTestNow();
    }
}
