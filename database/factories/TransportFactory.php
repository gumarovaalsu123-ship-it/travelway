<?php

namespace Database\Factories;

use App\Models\Transport;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transport>
 */
class TransportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $departureAt = fake()->dateTimeBetween('+1 week', '+2 months');

        return [
            'trip_id' => Trip::factory(),
            'type' => fake()->randomElement(Transport::Types),
            'departure_place' => fake()->city(),
            'arrival_place' => fake()->city(),
            'departure_at' => $departureAt,
            'arrival_at' => (clone $departureAt)->modify('+3 hours'),
            'booking_info' => fake()->optional()->bothify('??####'),
            'note' => null,
        ];
    }
}
