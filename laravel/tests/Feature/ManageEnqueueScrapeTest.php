<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manual "Scrape now" UX. The controller drives the same guard as the
 * scheduler, but on a denial it flashes a session status keyed off the
 * reason so the Vue side can render the right toast copy:
 *
 *   - within_spacing_window      → status=scrape-spacing-window
 *   - concurrency_cap_reached    → status=scrape-concurrency-cap
 *   - prior_job_not_yet_started  → status=scrape-queued-not-started
 *
 * On allowed, status=scrape-enqueued and a fresh queued row is created.
 */
class ManageEnqueueScrapeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $subscription;

    private BexSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'Manage Scrape Test Org',
        ]);

        $bexApp = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Manage Scrape Test App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $bexApp->id,
            'name' => 'Manage Scrape Test Sub',
            'environment' => 'production',
            'max_concurrent_jobs' => 1,
            'job_spacing_minutes' => 10,
        ]);

        $this->session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);
    }

    public function test_allowed_enqueue_creates_a_queued_job(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.scrape', $this->subscription));

        $response->assertRedirect(route('manage.index'));
        $response->assertSessionHas('status', 'scrape-enqueued');

        $this->assertSame(
            1,
            ScrapeJob::query()->where('subscription_id', $this->subscription->id)->count(),
        );
    }

    public function test_concurrency_cap_denial_flashes_scrape_concurrency_cap(): void
    {
        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
            'last_heartbeat_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.scrape', $this->subscription));

        $response->assertRedirect(route('manage.index'));
        $response->assertSessionHas('status', 'scrape-concurrency-cap');
        $response->assertSessionHas('scrape_denied_reason', 'concurrency_cap_reached');
        $response->assertSessionHas(
            'scrape_denied_message',
            fn (?string $msg) => is_string($msg) && str_contains($msg, 'Concurrency cap reached'),
        );

        $this->assertSame(
            1,
            ScrapeJob::query()->where('subscription_id', $this->subscription->id)->count(),
            'No additional job inserted when the cap denies.',
        );
    }

    public function test_within_spacing_window_denial_flashes_scrape_spacing_window(): void
    {
        $this->subscription->update(['max_concurrent_jobs' => 3, 'job_spacing_minutes' => 10]);

        Carbon::setTestNow('2026-05-04T20:00:30Z');

        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_RUNNING,
            'started_at' => Carbon::parse('2026-05-04T20:00:00Z'),
            'last_heartbeat_at' => Carbon::parse('2026-05-04T20:00:00Z'),
        ]);

        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.scrape', $this->subscription));

        $response->assertRedirect(route('manage.index'));
        $response->assertSessionHas('status', 'scrape-spacing-window');
        $response->assertSessionHas('scrape_denied_reason', 'within_spacing_window');

        Carbon::setTestNow();
    }

    public function test_prior_queued_not_started_denial_flashes_scrape_queued_not_started(): void
    {
        $this->subscription->update(['max_concurrent_jobs' => 3, 'job_spacing_minutes' => 10]);

        ScrapeJob::create([
            'subscription_id' => $this->subscription->id,
            'bex_session_id' => $this->session->id,
            'status' => ScrapeJob::STATUS_QUEUED,
        ]);

        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.scrape', $this->subscription));

        $response->assertRedirect(route('manage.index'));
        $response->assertSessionHas('status', 'scrape-queued-not-started');
        $response->assertSessionHas('scrape_denied_reason', 'prior_job_not_yet_started');
    }

    public function test_overrides_flow_into_job_params(): void
    {
        $this->subscription->update([
            'max_pages_per_scrape' => 200,
            'max_duration_minutes' => 10,
            'token_echo_max_attempts' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.scrape', $this->subscription), [
                'start_time' => '2026-05-15T00:00:00Z',
                'end_time' => '2026-05-18T17:42:31Z',
                'max_pages' => 999999,
                'max_duration_minutes' => 720,
                'token_echo_max_attempts' => 250,
                'early_stop_duplicate_pages' => 999999,
                'early_stop_min_duplicates' => 999999999,
            ]);

        $response->assertRedirect(route('manage.index'));
        $response->assertSessionHas('status', 'scrape-enqueued');

        $job = ScrapeJob::query()
            ->where('subscription_id', $this->subscription->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('2026-05-15T00:00:00Z', $job->params['start_time']);
        $this->assertSame('2026-05-18T17:42:31Z', $job->params['end_time']);
        $this->assertSame(999999, $job->params['max_pages']);
        $this->assertSame(720, $job->params['max_duration_minutes']);
        $this->assertSame(250, $job->params['token_echo_max_attempts']);
        $this->assertSame(999999, $job->params['early_stop_duplicate_pages']);
        $this->assertSame(999999999, $job->params['early_stop_min_duplicates']);
    }

    public function test_overrides_validation_rejects_out_of_range_values(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.scrape', $this->subscription), [
                'max_pages' => 1_000_001,
                'max_duration_minutes' => 1441,
                'token_echo_max_attempts' => 1001,
                'early_stop_duplicate_pages' => 1_000_001,
                'early_stop_min_duplicates' => 1_000_000_001,
            ]);

        $response->assertSessionHasErrors([
            'max_pages',
            'max_duration_minutes',
            'token_echo_max_attempts',
            'early_stop_duplicate_pages',
            'early_stop_min_duplicates',
        ]);

        $this->assertSame(
            0,
            ScrapeJob::query()->where('subscription_id', $this->subscription->id)->count(),
        );
    }

    public function test_empty_override_payload_falls_back_to_planner_defaults(): void
    {
        $this->subscription->update([
            'max_pages_per_scrape' => 250,
            'max_duration_minutes' => 15,
            'token_echo_max_attempts' => 75,
        ]);

        $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->post(route('manage.subscriptions.scrape', $this->subscription))
            ->assertSessionHas('status', 'scrape-enqueued');

        $job = ScrapeJob::query()
            ->where('subscription_id', $this->subscription->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(250, $job->params['max_pages']);
        $this->assertSame(15, $job->params['max_duration_minutes']);
        $this->assertSame(75, $job->params['token_echo_max_attempts']);
        $this->assertArrayNotHasKey('early_stop_duplicate_pages', $job->params);
        $this->assertArrayNotHasKey('early_stop_min_duplicates', $job->params);
    }

    public function test_update_validation_accepts_concurrency_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->patch(route('manage.subscriptions.update', $this->subscription), [
                'max_concurrent_jobs' => 5,
                'job_spacing_minutes' => 20,
            ]);

        $response->assertRedirect(route('manage.index'));
        $response->assertSessionHas('status', 'subscription-updated');

        $this->subscription->refresh();
        $this->assertSame(5, $this->subscription->max_concurrent_jobs);
        $this->assertSame(20, $this->subscription->job_spacing_minutes);
    }

    public function test_update_validation_rejects_out_of_range_concurrency_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->from(route('manage.index'))
            ->patch(route('manage.subscriptions.update', $this->subscription), [
                'max_concurrent_jobs' => 11,
                'job_spacing_minutes' => 121,
            ]);

        $response->assertSessionHasErrors(['max_concurrent_jobs', 'job_spacing_minutes']);

        $this->subscription->refresh();
        $this->assertSame(1, $this->subscription->max_concurrent_jobs);
        $this->assertSame(10, $this->subscription->job_spacing_minutes);
    }
}
