<?php

namespace Database\Factories;

use App\Models\Accommodation;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Accommodation>
 */
class AccommodationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = fake()->dateTimeBetween('+1 week', '+2 months')->setTime(15, 0);

        return [
            'trip_id' => Trip::factory(),
            'name' => 'Отель «'.fake()->lastName().'»',
            'address' => fake()->address(),
            'check_in' => $checkIn,
            'check_out' => (clone $checkIn)->modify('+2 days')->setTime(12, 0),
            'booking_info' => 'Бронь № '.fake()->bothify('??-######'),
            'latitude' => null,
            'longitude' => null,
            'note' => null,
        ];
    }
}
