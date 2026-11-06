<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('+1 week', '+2 months');

        return [
            'user_id' => User::factory(),
            'title' => fake()->city(),
            'destination' => fake()->city(),
            'start_date' => $startDate,
            'end_date' => (clone $startDate)->modify('+2 days'),
            'is_public' => false,
        ];
    }

    /**
     * Create the trip days right after the trip is created.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Trip $trip) => $trip->syncDays());
    }
}
