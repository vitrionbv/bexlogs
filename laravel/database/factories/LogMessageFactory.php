<?php

namespace Database\Factories;

use App\Models\LogMessage;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogMessage>
 */
class LogMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            // ISO8601 string with microseconds so we can mint many rows
            // per second without bumping into the `(page_id, timestamp,
            // type, action, method, status)` unique index.
            'timestamp' => now()->subSeconds(fake()->numberBetween(0, 86400))->toIso8601String(),
            'type' => 'http',
            'action' => fake()->word(),
            'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
            'status' => (string) fake()->randomElement([200, 201, 204, 400, 404, 500]),
            'parameters' => null,
            'request' => null,
            'response' => null,
        ];
    }
}
