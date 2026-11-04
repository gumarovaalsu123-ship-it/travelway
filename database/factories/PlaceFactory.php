<?php

namespace Database\Factories;

use App\Models\Place;
use App\Models\PlaceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Place>
 */
class PlaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => fn () => PlaceCategory::query()->inRandomOrder()->value('id'),
            'name' => fake()->streetName(),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(55.75, 55.83),
            'longitude' => fake()->longitude(49.05, 49.18),
        ];
    }
}
