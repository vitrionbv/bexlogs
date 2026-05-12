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
            // `content_hash` is NOT NULL on Postgres and participates in the
            // (page_id, content_hash) unique index. Production stores 32 raw
            // bytes via a typed bytea literal (WorkerController::ingest), but
            // Eloquent's default PDO binding sends params as text — Postgres
            // rejects non-ASCII bytes there with SQLSTATE[22021]. A 64-char
            // hex digest is round-trip safe over the text bind path on both
            // pgsql (stored verbatim in the bytea column) and sqlite, and
            // 256 bits of entropy keeps the unique index quiet in practice.
            'content_hash' => bin2hex(random_bytes(32)),
        ];
    }
}
