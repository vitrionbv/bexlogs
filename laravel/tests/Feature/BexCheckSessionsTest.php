<?php

namespace Tests\Feature;

use App\Jobs\DeliverAlertJob;
use App\Models\AlertChannel;
use App\Models\BexSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Covers `bex:check-sessions` (B5) — the cron command that emits
 * "session expiring within 48h" system alerts. The detector path
 * has three branches under test:
 *
 *   1. A session whose auth cookie expires inside the 48h window
 *      gets one DeliverAlertJob per system channel.
 *   2. A session whose cookie expires WAY beyond 48h is left alone
 *      (no false-positive page).
 *   3. A session WITHOUT any system channel opt-in is detected but
 *      no jobs are dispatched (operator hasn't asked for system
 *      alerts yet).
 */
class BexCheckSessionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_session_expiring_within_48h_triggers_one_alert_per_system_channel(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        $channel = AlertChannel::create([
            'user_id' => $this->user->id,
            'name' => 'System Slack',
            'kind' => AlertChannel::KIND_SLACK,
            'config_encrypted' => ['url' => 'https://hooks.slack.com/services/AAA/BBB/ccc'],
            'enabled' => true,
        ]);
        $this->user->systemAlertChannels()->sync([$channel->id]);

        // Cookies that lapse 12h from now → fall inside the 48h
        // warning window → BexSession::booted() saving hook should
        // tag the row `expiring_soon` automatically.
        //
        // NOTE: Crypt::encryptString (NOT encrypt(...)) — the model's
        // cookies accessor reverses with Crypt::decryptString, which
        // doesn't unserialize. encrypt()'s default serialize=true
        // produces a payload Crypt::decryptString returns as a
        // serialized string, so json_decode silently returns null
        // and the cookies look empty to the saving hook.
        $expires = Carbon::now()->addHours(12)->getTimestamp();
        $session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'account_email' => 'ops@example.com',
            'cookies_encrypted' => Crypt::encryptString(json_encode([
                ['name' => '_bex_session', 'value' => 'cookie', 'expirationDate' => $expires],
            ])),
            'captured_at' => now()->subHours(2),
        ]);

        $this->assertSame(BexSession::HEALTH_EXPIRING_SOON, $session->fresh()->health_status);

        $this->artisan('bex:check-sessions')->assertSuccessful();

        // One channel × one expiring session = one delivery dispatch.
        Bus::assertDispatchedTimes(DeliverAlertJob::class, 1);
        Bus::assertDispatched(
            DeliverAlertJob::class,
            fn (DeliverAlertJob $job) => $job->alertChannelId === $channel->id
                && $job->savedQueryId === null,
        );
    }

    public function test_session_with_long_lived_cookies_does_not_trigger_alert(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        $channel = AlertChannel::create([
            'user_id' => $this->user->id,
            'name' => 'System Slack',
            'kind' => AlertChannel::KIND_SLACK,
            'config_encrypted' => ['url' => 'https://hooks.slack.com/services/AAA/BBB/ccc'],
            'enabled' => true,
        ]);
        $this->user->systemAlertChannels()->sync([$channel->id]);

        // Cookies good for 30 days — well past the 48h warning
        // window. Saving hook should land on `healthy`.
        $expires = Carbon::now()->addDays(30)->getTimestamp();
        $session = BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'account_email' => 'ops@example.com',
            'cookies_encrypted' => Crypt::encryptString(json_encode([
                ['name' => '_bex_session', 'value' => 'cookie', 'expirationDate' => $expires],
            ])),
            'captured_at' => now()->subHours(2),
        ]);

        $this->assertSame(BexSession::HEALTH_HEALTHY, $session->fresh()->health_status);

        $this->artisan('bex:check-sessions')->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    public function test_user_without_system_channels_logs_but_does_not_dispatch(): void
    {
        Bus::fake([DeliverAlertJob::class]);

        $expires = Carbon::now()->addHours(6)->getTimestamp();
        BexSession::create([
            'user_id' => $this->user->id,
            'environment' => 'production',
            'account_email' => 'ops@example.com',
            'cookies_encrypted' => Crypt::encryptString(json_encode([
                ['name' => '_bex_session', 'value' => 'cookie', 'expirationDate' => $expires],
            ])),
            'captured_at' => now()->subHours(2),
        ]);

        $this->artisan('bex:check-sessions')->assertSuccessful();

        // Detection still fires but there's nowhere to send the
        // alert — SystemAlertEmitter returns 0 dispatches when the
        // user has no system channels. The cron's job is to be
        // idempotent for this case (operator opts into system
        // channels later → next cron picks up the page).
        Bus::assertNothingDispatched();
    }
}
