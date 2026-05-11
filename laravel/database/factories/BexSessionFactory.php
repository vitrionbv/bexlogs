<?php

namespace Database\Factories;

use App\Models\BexSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<BexSession>
 */
class BexSessionFactory extends Factory
{
    public function definition(): array
    {
        // Encrypted blob is required (NOT NULL on the column) — we
        // populate it with a small but recognisable payload so a test
        // that accidentally reads `cookies_encrypted` over the API
        // would be obvious in the assertion diff.
        $cookies = [[
            'name' => '_app_session',
            'value' => 'super-secret-cookie-value',
            'domain' => 'app.bookingexperts.nl',
            'path' => '/',
            'httpOnly' => true,
            'secure' => true,
        ]];

        return [
            'user_id' => User::factory(),
            'environment' => 'production',
            'cookies_encrypted' => Crypt::encryptString(json_encode($cookies)),
            'account_email' => fake()->safeEmail(),
            'account_name' => fake()->name(),
            'captured_at' => now(),
            'last_validated_at' => null,
            'expired_at' => null,
        ];
    }
}
