<?php

namespace Tests\Feature;

use App\Console\Commands\BexCheckFailureRuns;
use App\Jobs\DeliverAlertJob;
use App\Models\AlertChannel;
use App\Models\Application;
use App\Models\BexSession;
use App\Models\Organization;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers `bex:check-failure-runs` (B5) — the cron command that emits
 * "consecutive failures" and "quiet subscription" alerts.
 */
class BexCheckFailureRunsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $sub;

    private AlertChannel $channel;

    private BexSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-'.Str::random(8),
            'user_id' => $this->user->id,
            'name' => 'FR Test Org',
        ]);
        $app = Application::create([
            'id' => 'app-'.Str::random(8),
            'organization_id' => $org->id,
            'name' => 'FR Test App',
        ]);
        $this->sub = Subscription::create([
            'id' => 'sub-'.Str::random(8),
            'application_id' => $app->id,
            'name' => 'FR Test Sub',
            'environment' => 'production',
            'auto_scrape' => true,
            'last_scraped_at' => now(),
        ]);

        $this->channel = AlertChannel::create([
            'user_id' => $this->user->id,
            'name' => 'FR Slack',
            'kind' => AlertChannel::KIND_SLACK,
            'config_encrypted' => ['url' => 'https://hooks.slack.com/services/AAA/BBB/ccc'],
            'enabled' => true,
        ]);
        $this->user->systemAlertChannels()->sync([$this->channel->id]);

        // ScrapeJob has a NOT NULL FK to bex_sessions; without this row
        // the failure-run inserts trip the constraint before the
        // detector even sees them. Cookies don't matter for this suite —
        // we just need a valid session id to attach.
        $this->session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'cookies_encrypted' => encrypt(json_encode([])),
            'captured_at' => now(),
        ]);
    }

    public function test_three_consecutive_failures_of_same_reason_dispatches_alert(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        // Three failed jobs in a row, all with `runaway_safety` —
        // the same stop_reason crosses the FAILURE_RUN_THRESHOLD
        // and should trigger a consecutive-failures alert.
        for ($i = 0; $i < BexCheckFailureRuns::FAILURE_RUN_THRESHOLD; $i++) {
            ScrapeJob::create([
                'subscription_id' => $this->sub->id,
                'bex_session_id' => $this->session->id,
                'status' => ScrapeJob::STATUS_FAILED,
                'stats' => ['stop_reason' => 'runaway_safety'],
                'started_at' => now()->subMinutes(60 - $i * 5),
                'completed_at' => now()->subMinutes(58 - $i * 5),
            ]);
        }

        $this->artisan('bex:check-failure-runs')->assertSuccessful();

        Bus::assertDispatchedTimes(DeliverAlertJob::class, 1);
    }

    public function test_mixed_stop_reasons_do_not_trigger_consecutive_failures(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        // Three failed jobs but with three different stop_reasons —
        // probably noise rather than a fixable systemic issue, so
        // the detector intentionally stays quiet.
        $reasons = ['runaway_safety', 'pagination_error', 'session_expired'];
        foreach ($reasons as $i => $reason) {
            ScrapeJob::create([
                'subscription_id' => $this->sub->id,
                'bex_session_id' => $this->session->id,
                'status' => ScrapeJob::STATUS_FAILED,
                'stats' => ['stop_reason' => $reason],
                'started_at' => now()->subMinutes(60 - $i * 5),
                'completed_at' => now()->subMinutes(58 - $i * 5),
            ]);
        }

        $this->artisan('bex:check-failure-runs')->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    public function test_quiet_subscription_alert_fires_when_last_scraped_is_older_than_threshold(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        $this->sub->update([
            'last_scraped_at' => Carbon::now()->subHours(BexCheckFailureRuns::QUIET_THRESHOLD_HOURS + 6),
        ]);

        $this->artisan('bex:check-failure-runs')->assertSuccessful();

        Bus::assertDispatchedTimes(DeliverAlertJob::class, 1);
    }

    public function test_recent_scrape_does_not_trigger_quiet_alert(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        $this->sub->update(['last_scraped_at' => now()->subMinutes(10)]);

        $this->artisan('bex:check-failure-runs')->assertSuccessful();

        Bus::assertNothingDispatched();
    }
}
