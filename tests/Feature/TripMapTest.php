<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TripMapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->trip = Trip::factory()->for($this->user)->create([
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth()->addDays(2),
        ]);
    }

    private function day(int $number): TripDay
    {
        return $this->trip->days()->where('day_number', $number)->sole();
    }

    private function mapUrl(string $query = ''): string
    {
        return "/my-trips/{$this->trip->id}/map{$query}";
    }

    public function test_map_shows_places_of_the_day_numbered_in_plan_order(): void
    {
        $second = $this->day(2);
        $museum = $second->addPlace(Place::factory()->create(['name' => 'Музей', 'latitude' => 55.79, 'longitude' => 49.12]));
        $cafe = $second->addPlace(Place::factory()->create(['name' => 'Кафе']));
        $park = $second->addPlace(Place::factory()->create(['name' => 'Парк']));
        $museum->update(['start_time' => '10:00', 'end_time' => '12:00', 'calculated_mode' => 'walk', 'travel_minutes' => 12]);

        // Парк передвинули вверх: в плане он теперь второй — и на карте тоже.
        $park->moveUp();

        $this->actingAs($this->user)
            ->get($this->mapUrl('?day=2'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Map')
                ->where('trip.id', $this->trip->id)
                ->has('days', 3)
                ->where('days.1.placesCount', 3)
                ->where('selectedDay.number', 2)
                ->has('selectedDay.places', 3)
                ->where('selectedDay.places.0.name', 'Музей')
                ->where('selectedDay.places.0.number', 1)
                ->where('selectedDay.places.0.lat', 55.79)
                ->where('selectedDay.places.0.lng', 49.12)
                ->where('selectedDay.places.0.startTime', '10:00')
                ->where('selectedDay.places.0.mode', 'walk')
                ->where('selectedDay.places.0.travelMinutes', 12)
                ->where('selectedDay.places.1.name', 'Парк')
                ->where('selectedDay.places.1.number', 2)
                ->where('selectedDay.places.2.id', $cafe->id)
                ->where('selectedDay.places.2.number', 3)
                ->where('selectedDay.morningLodging', null));
    }

    public function test_day_starts_and_ends_at_the_lodging(): void
    {
        $first = $this->day(1);
        $first->addPlace(Place::factory()->create());
        $hotel = Accommodation::factory()->for($this->trip)->create([
            'name' => 'Отель у реки',
            'latitude' => 55.78,
            'longitude' => 49.11,
            'check_in' => $this->trip->start_date->copy()->setTime(14, 0),
            'check_out' => $this->trip->end_date->copy()->setTime(12, 0),
        ]);

        $this->actingAs($this->user)
            ->get($this->mapUrl('?day=1'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.morningLodging.id', $hotel->id)
                ->where('selectedDay.morningLodging.name', 'Отель у реки')
                ->where('selectedDay.morningLodging.lat', 55.78)
                ->where('selectedDay.eveningLodging.id', $hotel->id));

        // В день выезда вечером к жилью не возвращаемся — день заканчивается у последнего места.
        $this->actingAs($this->user)
            ->get($this->mapUrl('?day=3'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.morningLodging.id', $hotel->id)
                ->where('selectedDay.eveningLodging', null));
    }

    public function test_lodging_without_coordinates_is_not_on_the_map(): void
    {
        Accommodation::factory()->for($this->trip)->create(['latitude' => null, 'longitude' => null]);

        $this->actingAs($this->user)
            ->get($this->mapUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.morningLodging', null)
                ->where('selectedDay.eveningLodging', null));
    }

    public function test_unknown_day_opens_day_one(): void
    {
        foreach (['?day=99', '?day=0', '?day=abc', ''] as $query) {
            $this->actingAs($this->user)
                ->get($this->mapUrl($query))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('selectedDay.number', 1)
                    ->has('selectedDay.places', 0));
        }
    }

    public function test_stranger_cannot_open_the_map(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->mapUrl())
            ->assertForbidden();
    }

    public function test_show_on_map_opens_the_check_in_day_with_the_lodging_focused(): void
    {
        $lodging = Accommodation::factory()->for($this->trip)->create([
            'name' => 'Отель «Волга»',
            'latitude' => 55.79,
            'longitude' => 49.12,
            'check_in' => today()->addMonth()->addDay()->setTime(15, 0),
        ]);

        $this->actingAs($this->user)
            ->get($this->mapUrl("?lodging={$lodging->id}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.number', 2)
                ->where('focusLodging.id', $lodging->id)
                ->where('focusLodging.name', 'Отель «Волга»')
                ->where('focusLodging.lat', 55.79)
                ->where('focusLodging.lng', 49.12));

        // Выбранный вручную день важнее дня заезда.
        $this->actingAs($this->user)
            ->get($this->mapUrl("?lodging={$lodging->id}&day=3"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.number', 3)
                ->where('focusLodging.id', $lodging->id));
    }

    public function test_lodging_of_another_trip_or_without_coordinates_is_not_focused(): void
    {
        $foreign = Accommodation::factory()->create(['latitude' => 55.79, 'longitude' => 49.12]);
        $withoutCoordinates = Accommodation::factory()->for($this->trip)->create(['latitude' => null, 'longitude' => null]);

        foreach ([$foreign, $withoutCoordinates] as $lodging) {
            $this->actingAs($this->user)
                ->get($this->mapUrl("?lodging={$lodging->id}"))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('focusLodging', null));
        }
    }
}
