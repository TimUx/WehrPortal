<?php

namespace Database\Factories;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $starts = Carbon::instance(fake()->dateTimeBetween('now', '+3 months'))->setTimezone('UTC');
        $starts->setMinute(0)->setSecond(0)->setMicrosecond(0);
        $ends = $starts->copy()->addHours(fake()->numberBetween(1, 8));

        return [
            'vehicle_id' => Vehicle::factory(),
            'user_id' => User::factory(),
            'group_id' => null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'purpose' => fake()->sentence(3),
            'destination' => fake()->optional(0.5)->city(),
            'notes' => fake()->optional(0.2)->paragraph(),
            'cancelled_at' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(['cancelled_at' => now()]);
    }

    public function past(): static
    {
        return $this->state(function () {
            $starts = now()->subDays(fake()->numberBetween(1, 30))->setMinute(0)->setSecond(0);

            return [
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHours(4),
            ];
        });
    }
}
