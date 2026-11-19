<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripTask>
 */
class TripTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory(),
            'title' => fake()->randomElement(['Оформить страховку', 'Обменять деньги', 'Распечатать билеты', 'Записаться на экскурсию']),
            'due_date' => fake()->optional()->dateTimeBetween('+1 day', '+1 month'),
            'is_done' => false,
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => ['is_done' => true]);
    }
}
