<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Page;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    public function definition(): array
    {
        // Default factory wires up a coherent (org, app, sub) triple so
        // the unique `pages_unique_idx` constraint is satisfied without
        // the caller having to spell each one out. Tests that already
        // have an Organization / Application / Subscription should pass
        // those ids in explicitly to avoid creating duplicate parents.
        $organization = Organization::factory()->create();
        $application = Application::factory()->create([
            'organization_id' => $organization->id,
        ]);
        $subscription = Subscription::factory()->create([
            'application_id' => $application->id,
        ]);

        return [
            'organization_id' => $organization->id,
            'application_id' => $application->id,
            'subscription_id' => $subscription->id,
        ];
    }
}
