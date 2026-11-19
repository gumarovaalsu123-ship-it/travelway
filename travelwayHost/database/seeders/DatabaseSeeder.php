<?php

namespace Database\Seeders;

use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Администратор',
            'email' => 'admin@travelway.test',
            'password' => 'password',
            'role_id' => Role::Admin,
        ]);

        $user = User::factory()->create([
            'name' => 'Тестовый Пользователь',
            'email' => 'test@travelway.test',
            'password' => 'password',
        ]);

        $trip = Trip::factory()->for($user)->create([
            'title' => 'Выходные в Казани',
            'destination' => 'Казань',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(2)->toDateString(),
        ]);

        $categoryIds = PlaceCategory::query()->pluck('id', 'slug');
        $firstDay = $trip->days()->first();

        $stops = [
            ['name' => 'Казанский Кремль', 'category' => 'attraction', 'address' => 'Кремлёвская ул., 2', 'latitude' => 55.7986, 'longitude' => 49.1059, 'time' => '10:00'],
            ['name' => 'Улица Баумана', 'category' => 'attraction', 'address' => 'ул. Баумана', 'latitude' => 55.7903, 'longitude' => 49.1145, 'time' => '13:00'],
            ['name' => 'Дом чая', 'category' => 'restaurant', 'address' => 'ул. Баумана, 64', 'latitude' => 55.7934, 'longitude' => 49.1118, 'time' => '14:30'],
        ];

        foreach ($stops as $index => $stop) {
            $place = Place::factory()->create([
                'user_id' => $user->id,
                'category_id' => $categoryIds[$stop['category']],
                'name' => $stop['name'],
                'address' => $stop['address'],
                'latitude' => $stop['latitude'],
                'longitude' => $stop['longitude'],
            ]);

            $trip->tripPlaces()->create([
                'place_id' => $place->id,
                'trip_day_id' => $firstDay->id,
                'start_time' => $stop['time'],
                'visit_order' => $index + 1,
            ]);
        }

        $favorite = Place::factory()->create([
            'user_id' => $user->id,
            'category_id' => $categoryIds['viewpoint'],
            'name' => 'Чаша (ЗАГС «Чаша»)',
            'address' => 'ул. Шейнкмана, 38',
            'latitude' => 55.8007,
            'longitude' => 49.1003,
        ]);

        $user->favoritePlaces()->attach($favorite->id, ['created_at' => now()]);

        Trip::factory()->for($user)->create([
            'title' => 'Майские каникулы в горах Грузии',
            'destination' => 'Грузия, Тбилиси',
            'start_date' => null,
            'end_date' => null,
            'days_count' => 5,
        ]);

        Trip::factory()->for($user)->create([
            'title' => 'Осенний Петербург',
            'destination' => 'Санкт-Петербург',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subMonth()->addDays(3)->toDateString(),
        ]);
    }
}
