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

/**
 * Карта дня на вкладке «План»: данные для неё отдаёт TripPlanController@index,
 * старый адрес отдельной вкладки «Карта» ведёт на план.
 */
class TripPlanMapTest extends TestCase
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

    private function planUrl(string $query = ''): string
    {
        return "/my-trips/{$this->trip->id}/plan{$query}";
    }

    public function test_plan_gives_the_map_places_of_the_day_in_plan_order(): void
    {
        $second = $this->day(2);
        $museum = $second->addPlace(Place::factory()->create(['name' => 'Музей', 'latitude' => 55.79, 'longitude' => 49.12]));
        $cafe = $second->addPlace(Place::factory()->create(['name' => 'Кафе']));
        $park = $second->addPlace(Place::factory()->create(['name' => 'Парк']));
        $museum->update(['start_time' => '10:00', 'end_time' => '12:00', 'calculated_mode' => 'walk', 'travel_minutes' => 12]);
        $cafe->update(['travel_mode' => 'taxi']);

        // Парк передвинули вверх: в списке он теперь второй — и номер маркера на карте тоже 2.
        $park->moveUp();

        $this->actingAs($this->user)
            ->get($this->planUrl('?day=2'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Plan')
                ->where('selectedDay.number', 2)
                ->has('selectedDay.places', 3)
                ->where('selectedDay.places.0.name', 'Музей')
                ->where('selectedDay.places.0.lat', 55.79)
                ->where('selectedDay.places.0.lng', 49.12)
                ->where('selectedDay.places.0.mode', 'walk')
                ->where('selectedDay.places.1.name', 'Парк')
                ->where('selectedDay.places.1.mode', null)
                ->where('selectedDay.places.2.id', $cafe->id)
                // Время ещё не рассчитано — линия к кафе рисуется по выбранному способу.
                ->where('selectedDay.places.2.mode', 'taxi')
                ->where('selectedDay.morningLodging', null)
                ->where('selectedDay.eveningLodging', null)
                ->where('focusLodging', null));
    }

    public function test_day_on_the_map_starts_and_ends_at_the_lodging(): void
    {
        $this->day(1)->addPlace(Place::factory()->create());
        $hotel = Accommodation::factory()->for($this->trip)->create([
            'name' => 'Отель у реки',
            'latitude' => 55.78,
            'longitude' => 49.11,
            'check_in' => $this->trip->start_date->copy()->setTime(14, 0),
            'check_out' => $this->trip->end_date->copy()->setTime(12, 0),
        ]);

        $this->actingAs($this->user)
            ->get($this->planUrl('?day=1'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.morningLodging.id', $hotel->id)
                ->where('selectedDay.morningLodging.name', 'Отель у реки')
                ->where('selectedDay.morningLodging.lat', 55.78)
                ->where('selectedDay.eveningLodging.id', $hotel->id));

        // В день выезда вечером к жилью не возвращаемся — день заканчивается у последнего места.
        $this->actingAs($this->user)
            ->get($this->planUrl('?day=3'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.morningLodging.id', $hotel->id)
                ->where('selectedDay.eveningLodging', null));
    }

    public function test_lodging_without_coordinates_is_not_on_the_map(): void
    {
        Accommodation::factory()->for($this->trip)->create(['latitude' => null, 'longitude' => null]);

        $this->actingAs($this->user)
            ->get($this->planUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.morningLodging', null)
                ->where('selectedDay.eveningLodging', null));
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
            ->get($this->planUrl("?lodging={$lodging->id}"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.number', 2)
                ->where('focusLodging.id', $lodging->id)
                ->where('focusLodging.name', 'Отель «Волга»')
                ->where('focusLodging.lat', 55.79)
                ->where('focusLodging.lng', 49.12));

        // Выбранный вручную день важнее дня заезда.
        $this->actingAs($this->user)
            ->get($this->planUrl("?lodging={$lodging->id}&day=3"))
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
                ->get($this->planUrl("?lodging={$lodging->id}"))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('focusLodging', null));
        }
    }

    public function test_old_map_address_redirects_to_the_plan_keeping_the_day_and_the_lodging(): void
    {
        $mapUrl = "/my-trips/{$this->trip->id}/map";

        $this->actingAs($this->user)->get($mapUrl)->assertRedirect($this->planUrl());
        $this->get("{$mapUrl}?day=2")->assertRedirect($this->planUrl('?day=2'));
        $this->get("{$mapUrl}?lodging=5")->assertRedirect($this->planUrl('?lodging=5'));
    }

    public function test_there_is_no_separate_map_tab(): void
    {
        $this->actingAs($this->user)
            ->get($this->planUrl())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trip.tabs.plan', true)
                ->missing('trip.tabs.map'));
    }
}
