<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id' => Str::lower(Str::random(12)),
            'organization_id' => Organization::factory(),
            'name' => fake()->words(2, true),
        ];
    }
}
