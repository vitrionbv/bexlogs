<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for the data-lifecycle validation contract on
 * `ManageController::updateSubscription`. Exercises:
 *
 *   - retention_days accepts NULL = "keep forever",
 *   - retention_days accepts an in-range integer (1..3650),
 *   - retention_days rejects 0 (lower bound),
 *   - retention_days rejects above-ceiling values,
 *   - archive_after_days accepts NULL = "never archive",
 *   - archive_after_days enforces its 7-day floor,
 *   - the index payload exposes both fields + the `lifecycle_counts`
 *     hint object the Vue page renders alongside the inputs.
 */
class ManageLifecycleValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $org = Organization::create([
            'id' => 'org-lifecycle',
            'user_id' => $this->user->id,
            'name' => 'Lifecycle Org',
        ]);

        $app = Application::create([
            'id' => 'app-lifecycle',
            'organization_id' => $org->id,
            'name' => 'Lifecycle App',
        ]);

        $this->sub = Subscription::create([
            'id' => 'sub-lifecycle',
            'application_id' => $app->id,
            'name' => 'Lifecycle Sub',
            'environment' => 'production',
        ]);
    }

    public function test_retention_days_accepts_null(): void
    {
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->sub),
                ['retention_days' => null],
            )
            ->assertRedirect();

        $this->sub->refresh();
        $this->assertNull($this->sub->retention_days);
    }

    public function test_retention_days_accepts_valid_value(): void
    {
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->sub),
                ['retention_days' => 90],
            )
            ->assertRedirect();

        $this->sub->refresh();
        $this->assertSame(90, $this->sub->retention_days);
    }

    public function test_retention_days_rejects_zero(): void
    {
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->sub),
                ['retention_days' => 0],
            )
            ->assertSessionHasErrors('retention_days');
    }

    public function test_retention_days_rejects_above_ceiling(): void
    {
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->sub),
                ['retention_days' => 3651],
            )
            ->assertSessionHasErrors('retention_days');
    }

    public function test_archive_after_days_accepts_null(): void
    {
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->sub),
                ['archive_after_days' => null],
            )
            ->assertRedirect();

        $this->sub->refresh();
        $this->assertNull($this->sub->archive_after_days);
    }

    public function test_archive_after_days_accepts_valid_value(): void
    {
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->sub),
                ['archive_after_days' => 90],
            )
            ->assertRedirect();

        $this->sub->refresh();
        $this->assertSame(90, $this->sub->archive_after_days);
    }

    public function test_archive_after_days_rejects_below_floor(): void
    {
        // 6 days is below the 7-day floor — the controller surface
        // exists to prevent operators from archiving rows before
        // they've had a chance to inspect them in the hot tier.
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->sub),
                ['archive_after_days' => 6],
            )
            ->assertSessionHasErrors('archive_after_days');
    }

    public function test_archive_after_days_rejects_above_ceiling(): void
    {
        $this->actingAs($this->user)
            ->patch(
                route('manage.subscriptions.update', $this->sub),
                ['archive_after_days' => 3651],
            )
            ->assertSessionHasErrors('archive_after_days');
    }

    public function test_index_payload_exposes_lifecycle_fields_with_defaults(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('manage.index'))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('organizations.0.applications.0.subscriptions.0.retention_days', null)
            ->where('organizations.0.applications.0.subscriptions.0.archive_after_days', null)
            ->where('organizations.0.applications.0.subscriptions.0.lifecycle_counts.hot_rows_older_than_retention', 0)
            ->where('organizations.0.applications.0.subscriptions.0.lifecycle_counts.archived_row_count', 0)
        );
    }
}
