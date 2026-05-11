<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            // The id is the BookingExperts org slug in real data; for
            // tests a short random hex string is plenty unique.
            'id' => Str::lower(Str::random(12)),
            'user_id' => User::factory(),
            'name' => fake()->company(),
        ];
    }
}
