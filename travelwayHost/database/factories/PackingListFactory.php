<?php

namespace Database\Factories;

use App\Models\PackingList;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackingList>
 */
class PackingListFactory extends Factory
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
            'title' => fake()->randomElement(array_keys(PackingList::Defaults)),
            'position' => 1,
        ];
    }
}
