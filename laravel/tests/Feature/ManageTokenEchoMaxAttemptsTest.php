<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the operator-facing read/write path for the new
 * `token_echo_max_attempts` knob:
 *
 *   1. The Manage page renders the field with its current value, so
 *      the operator can see what the worker will use on the next run.
 *   2. PATCH /manage/subscriptions/{id} validates the range
 *      (1..1000) — anything outside that comes back as a 422 so a
 *      typo can't wedge a worker for hours on a quiet sub.
 *   3. A valid update lands in the column and the planner picks it
 *      up on the next /jobs/next handoff (covered indirectly: this
 *      test only asserts the column writes; the planner side is
 *      covered in ScrapeWindowPlannerTest).
 */
class ManageTokenEchoMaxAttemptsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-tea',
            'user_id' => $this->user->id,
            'name' => 'TEA Org',
        ]);

        $app = Application::create([
            'id' => 'app-tea',
            'organization_id' => $org->id,
            'name' => 'TEA App',
        ]);

        $this->subscription = Subscription::create([
            'id' => 'sub-tea',
            'application_id' => $app->id,
            'name' => 'TEA Sub',
            'environment' => 'production',
        ]);
    }

    public function test_manage_index_payload_exposes_token_echo_max_attempts(): void
    {
        // A fresh subscription inherits the schema default (100), so
        // the Manage page must render that value back to the operator
        // unchanged. If this assertion ever sees a different number,
        // it means either the migration default drifted from the
        // model default (they MUST agree — see Subscription's
        // `$attributes` array) or the controller dropped the field
        // from the payload mapping.
        $response = $this->actingAs($this->user)
            ->get(route('manage.index'))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('organizations.0.applications.0.subscriptions.0.token_echo_max_attempts', 100)
        );
    }

    public function test_patch_accepts_valid_value_and_persists_it(): void
    {
        // 250 is a deliberately non-default, non-rounded value so a
        // silent coercion would be visible in the post-update read.
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->subscription),
                ['token_echo_max_attempts' => 250],
            )
            ->assertRedirect();

        $this->subscription->refresh();
        $this->assertSame(250, $this->subscription->token_echo_max_attempts);
    }

    public function test_patch_rejects_zero(): void
    {
        // Lower bound is 1 (the smallest meaningful retry policy:
        // try once, don't retry). Zero is invalid because it would
        // skip the initial attempt — the worker uses the value as
        // "1 initial + (maxAttempts - 1) retries".
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->subscription),
                ['token_echo_max_attempts' => 0],
            )
            ->assertSessionHasErrors('token_echo_max_attempts');
    }

    public function test_patch_rejects_above_ceiling(): void
    {
        // 1000 is the controller's hard ceiling (10× the default).
        // Above that, even at the 3s flat schedule, a fully-
        // exhausted echo cluster would burn ~50 min of sleep —
        // bigger than any sane per-job budget.
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->subscription),
                ['token_echo_max_attempts' => 1001],
            )
            ->assertSessionHasErrors('token_echo_max_attempts');
    }

    public function test_patch_rejects_non_integer(): void
    {
        // The column is `unsignedSmallInteger`. Letting a float
        // through would silently truncate at the DB layer and the
        // resulting row would no longer match what the operator
        // typed — strict-integer validation is the right contract.
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->subscription),
                ['token_echo_max_attempts' => 'abc'],
            )
            ->assertSessionHasErrors('token_echo_max_attempts');
    }
}
