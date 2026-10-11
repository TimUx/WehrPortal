<?php

namespace Database\Factories;

use App\Models\LoginToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoginToken>
 */
class LoginTokenFactory extends Factory
{
    public function definition(): array
    {
        $rawToken = Str::random(64);

        return [
            'user_id' => User::factory(),
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinutes(1)]);
    }

    public function used(): static
    {
        return $this->state(['used_at' => now()->subMinutes(5)]);
    }
}
