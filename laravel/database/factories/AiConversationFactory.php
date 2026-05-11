<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConversation>
 */
class AiConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subscription_id' => Subscription::factory(),
            'title' => fake()->sentence(4),
            'model' => null,
        ];
    }
}
