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

    /**
     * Place with photo, description and contacts, as if they came from OpenStreetMap and Wikipedia.
     */
    public function withDetails(): static
    {
        return $this->state(fn (array $attributes) => [
            'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Kazan_Kremlin.jpg/320px-Kazan_Kremlin.jpg',
            'description' => fake()->sentence(12),
            'opening_hours' => 'Mo-Su 08:00-22:00',
            'website' => 'https://example.com',
            'phone' => '+7 843 567-80-01',
            'wiki_url' => 'https://ru.wikipedia.org/wiki/Казанский_кремль',
        ]);
    }
}
