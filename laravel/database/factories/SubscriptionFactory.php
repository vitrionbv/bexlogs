<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => Str::lower(Str::random(12)),
            'application_id' => Application::factory(),
            'name' => fake()->word(),
            'environment' => 'production',
            'auto_scrape' => true,
            'scrape_interval_minutes' => 5,
        ];
    }
}
