<?php

namespace Database\Factories;

use App\Models\BexSession;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapeJob>
 */
class ScrapeJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'bex_session_id' => BexSession::factory(),
            'status' => ScrapeJob::STATUS_QUEUED,
            'attempts' => 0,
        ];
    }
}
