<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'short_name' => strtoupper(fake()->lexify('???')),
            'color' => fake()->hexColor(),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
