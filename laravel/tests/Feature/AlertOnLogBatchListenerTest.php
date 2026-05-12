<?php

namespace Tests\Feature;

use App\Events\LogBatchInserted;
use App\Jobs\DeliverAlertJob;
use App\Listeners\AlertOnLogBatchListener;
use App\Models\AlertChannel;
use App\Models\Application;
use App\Models\LogMessage;
use App\Models\Organization;
use App\Models\Page;
use App\Models\SavedQuery;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the B4 listener path:
 *
 *   1. A LogBatchInserted event with a matching saved query dispatches
 *      ONE DeliverAlertJob per linked channel.
 *   2. A second event in the same dedupe window collapses to zero
 *      additional dispatches.
 *
 * The listener is exercised directly (via `Event::fake()` for the
 * registration assertion + manual handle()) rather than through the
 * /batch endpoint to keep the test focused on the alert-evaluation
 * logic — the controller path has its own tests.
 */
class AlertOnLogBatchListenerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Page $page;

    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'Listener Test Org',
        ]);

        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'Listener Test App',
        ]);

        $this->sub = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'Listener Test Sub',
            'environment' => 'production',
        ]);

        $this->page = Page::create([
            'organization_id' => $org->id,
            'application_id' => $app->id,
            'subscription_id' => $this->sub->id,
        ]);
    }

    public function test_listener_is_registered_for_log_batch_inserted_event(): void
    {
        // Sanity check that AppServiceProvider::wireAlertListeners
        // ran at boot. Without this the rest of the suite would
        // pass on a broken wiring (manual handle() calls succeed
        // even when the event listener registration is missing).
        Event::fake([LogBatchInserted::class]);
        Event::assertListening(LogBatchInserted::class, AlertOnLogBatchListener::class);
    }

    public function test_matching_log_dispatches_one_delivery_job_per_channel(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        // Two channels both wired to the same query — the listener
        // should fan one match out to both, matching B4's contract.
        $slack = AlertChannel::create([
            'user_id' => $this->user->id,
            'name' => 'Listener Slack',
            'kind' => AlertChannel::KIND_SLACK,
            'config_encrypted' => ['url' => 'https://hooks.slack.com/services/AAA/BBB/ccc'],
            'enabled' => true,
        ]);
        $email = AlertChannel::create([
            'user_id' => $this->user->id,
            'name' => 'Listener Email',
            'kind' => AlertChannel::KIND_EMAIL,
            'config_encrypted' => ['to_address' => 'ops@example.com'],
            'enabled' => true,
        ]);

        $query = SavedQuery::create([
            'user_id' => $this->user->id,
            'name' => 'All POSTs',
            'filter' => ['method' => 'POST'],
            'enabled' => true,
        ]);
        $query->channels()->sync([
            $slack->id => ['dedupe_window_seconds' => 60],
            $email->id => ['dedupe_window_seconds' => 60],
        ]);

        // Insert a log row that matches the filter, then fire the
        // event with the matching insert count.
        LogMessage::create([
            'page_id' => $this->page->id,
            'timestamp' => now()->toIso8601String(),
            'type' => 'request',
            'action' => 'BookingsController#create',
            'method' => 'POST',
            'status' => '201',
            'content_hash' => bin2hex(random_bytes(32)),
        ]);

        $event = new LogBatchInserted(
            userId: (int) $this->user->id,
            pageId: (int) $this->page->id,
            subscriptionId: (string) $this->sub->id,
            inserted: 1,
            totalInPage: 1,
            latestTimestamp: now()->toIso8601String(),
        );

        app(AlertOnLogBatchListener::class)->handle($event);

        // Two channels × one matching row = two dispatches.
        Bus::assertDispatchedTimes(DeliverAlertJob::class, 2);
        Bus::assertDispatched(DeliverAlertJob::class, fn ($job) => $job->alertChannelId === $slack->id);
        Bus::assertDispatched(DeliverAlertJob::class, fn ($job) => $job->alertChannelId === $email->id);
    }

    public function test_dedupe_window_prevents_second_delivery_to_same_channel(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        $channel = AlertChannel::create([
            'user_id' => $this->user->id,
            'name' => 'Dedupe Slack',
            'kind' => AlertChannel::KIND_SLACK,
            'config_encrypted' => ['url' => 'https://hooks.slack.com/services/AAA/BBB/ccc'],
            'enabled' => true,
        ]);
        $query = SavedQuery::create([
            'user_id' => $this->user->id,
            'name' => 'All 5xx',
            'filter' => ['status_regex' => '#^5\d\d$#'],
            'enabled' => true,
        ]);
        $query->channels()->sync([
            $channel->id => ['dedupe_window_seconds' => 60],
        ]);

        LogMessage::create([
            'page_id' => $this->page->id,
            'timestamp' => now()->toIso8601String(),
            'type' => 'request',
            'action' => 'BookingsController#create',
            'method' => 'POST',
            'status' => '500',
            'content_hash' => bin2hex(random_bytes(32)),
        ]);

        $event = new LogBatchInserted(
            userId: (int) $this->user->id,
            pageId: (int) $this->page->id,
            subscriptionId: (string) $this->sub->id,
            inserted: 1,
            totalInPage: 1,
            latestTimestamp: now()->toIso8601String(),
        );

        $listener = app(AlertOnLogBatchListener::class);
        $listener->handle($event);
        $listener->handle($event);

        // First handle dispatches once; the second is suppressed
        // by DedupeWindow because the (query, channel, fingerprint)
        // tuple is identical and we're inside the 60s window.
        Bus::assertDispatchedTimes(DeliverAlertJob::class, 1);
    }
}
