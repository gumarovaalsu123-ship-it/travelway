<?php

namespace Database\Factories;

use App\Models\PackingItem;
use App\Models\PackingList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackingItem>
 */
class PackingItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'packing_list_id' => PackingList::factory(),
            'name' => fake()->randomElement(['Паспорт', 'Зарядка', 'Аптечка', 'Футболки', 'Наушники']),
            'note' => null,
            'is_completed' => false,
            'position' => 1,
        ];
    }

    /**
     * The thing is already packed.
     */
    public function completed(): static
    {
        return $this->state(fn () => ['is_completed' => true]);
    }
}
