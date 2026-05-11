<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ScrapeWindowPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The planner converts a Subscription's budget knobs into the `params`
 * array that ends up on `scrape_jobs.params`. The Node worker reads
 * those params and uses them as per-job overrides for the env-wide
 * defaults in `scraper/src/config.ts` — see
 * `scraper/src/scrape.ts::tokenEchoMaxAttempts` for the override
 * resolution.
 *
 * This file specifically pins down `token_echo_max_attempts` because
 * it's the newest knob and the most operator-facing of the bunch
 * ("how long should the scraper keep retrying when BookingExperts
 * holds at the live tip?"). The other keys (`start_time`, `end_time`,
 * `max_pages`, `max_duration_minutes`) are covered indirectly through
 * `ScrapeEnqueueCommandTest`'s integration assertions.
 */
class ScrapeWindowPlannerTest extends TestCase
{
    use RefreshDatabase;

    private ScrapeWindowPlanner $planner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = new ScrapeWindowPlanner;
        $this->user = User::factory()->create();
    }

    public function test_params_include_token_echo_max_attempts_with_model_default_100(): void
    {
        // The migration that added this column shipped with default
        // 100, and the model's `$attributes` array seeds the same
        // value for freshly-instantiated rows. Both layers agree so a
        // sub created without an explicit override hits the worker
        // with the historical env default.
        $sub = $this->makeSub();

        $params = $this->planner->buildParams($sub);

        $this->assertSame(100, $params['token_echo_max_attempts']);
    }

    public function test_planner_passes_configured_token_echo_max_attempts_through_verbatim(): void
    {
        // Operator-configured override (the only point of the
        // feature). 250 is intentionally not 100 and not a power of 2
        // so a hidden coercion would be visible in the assertion.
        $sub = $this->makeSub(['token_echo_max_attempts' => 250]);

        $params = $this->planner->buildParams($sub);

        $this->assertSame(250, $params['token_echo_max_attempts']);
    }

    public function test_build_params_with_overrides_does_not_clobber_token_echo_max_attempts(): void
    {
        // Manual "Scrape now" overrides today only carry
        // start_time/end_time/max_pages/max_duration_minutes (see
        // ManageController::enqueueScrape). The planner's
        // mergeOverrides filters out nulls and preserves the
        // base-window keys, so the subscription's configured
        // token_echo_max_attempts survives an unrelated override
        // payload from the Scrape-now form.
        $sub = $this->makeSub(['token_echo_max_attempts' => 42]);

        $params = $this->planner->buildParamsWithOverrides(
            $sub,
            ['max_pages' => 12],
        );

        $this->assertSame(42, $params['token_echo_max_attempts']);
        $this->assertSame(12, $params['max_pages']);
    }

    public function test_token_echo_max_attempts_falls_back_to_100_when_column_is_null(): void
    {
        // Defensive coverage for the legacy-row case: a Subscription
        // somehow lacking the column value (a future migration that
        // adds a nullable column, a manual SQL UPDATE setting it to
        // NULL, etc.) must still produce a usable params payload.
        // The planner's `?? 100` fallback mirrors the model's
        // `$attributes` default so both layers agree.
        $sub = $this->makeSub();
        // Bypass the model's `$attributes` default by NULL'ing the
        // attribute directly on the in-memory instance.
        $sub->setAttribute('token_echo_max_attempts', null);

        $params = $this->planner->buildParams($sub);

        $this->assertSame(100, $params['token_echo_max_attempts']);
    }

    public function test_planner_params_include_all_documented_keys(): void
    {
        // Schema-level guard: the params array shape is the contract
        // the worker reads against. Adding a new key here (or
        // dropping one) must be a deliberate change paired with a
        // worker update, so this assertion locks down the current
        // set. Update both arrays when a new knob lands.
        $sub = $this->makeSub();

        $params = $this->planner->buildParams($sub);

        $this->assertSame(
            [
                'start_time',
                'end_time',
                'max_pages',
                'max_duration_minutes',
                'token_echo_max_attempts',
            ],
            array_keys($params),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSub(array $overrides = []): Subscription
    {
        $org = Organization::create([
            'id' => 'org-planner-'.uniqid(),
            'user_id' => $this->user->id,
            'name' => 'Planner Test Org',
        ]);

        $app = Application::create([
            'id' => 'app-planner-'.uniqid(),
            'organization_id' => $org->id,
            'name' => 'Planner Test App',
        ]);

        return Subscription::create(array_merge(
            [
                'id' => 'sub-planner-'.uniqid(),
                'application_id' => $app->id,
                'name' => 'Planner Test Sub',
                'environment' => 'production',
                // Pin the wall-clock-dependent base window so a slow
                // test doesn't accidentally race the planner's
                // `now()` calls. The token_echo assertions don't
                // depend on the time fields, but this keeps the test
                // output diff-friendly when debugging.
                'last_scraped_at' => Carbon::parse('2026-05-01T00:00:00Z'),
            ],
            $overrides,
        ));
    }
}
