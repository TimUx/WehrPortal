<?php

namespace Database\Factories;

use App\Models\ModuleUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional(0.4)->phoneNumber(),
            'is_admin' => false,
            'is_active' => true,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['is_admin' => true]);
    }

    public function decider(): static
    {
        return $this->afterCreating(function (User $user) {
            ModuleUser::updateOrCreate(
                ['user_id' => $user->id, 'module' => 'fahrzeugbuchung'],
                ['role' => 'entscheider'],
            );
        });
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
