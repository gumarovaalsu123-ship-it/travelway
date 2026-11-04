<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class InteractiveMapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->trip = Trip::factory()->for($this->user)->create([
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-17',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function placePayload(array $overrides = []): array
    {
        return [
            'name' => 'Смотровая площадка',
            'category_id' => PlaceCategory::firstWhere('slug', 'viewpoint')->id,
            'address' => 'Кремлёвская ул., 2',
            'latitude' => 55.7986,
            'longitude' => 49.1059,
            ...$overrides,
        ];
    }

    public function test_trip_days_are_created_for_every_date(): void
    {
        $this->assertSame([1, 2, 3], $this->trip->days()->pluck('day_number')->all());
        $this->assertSame('2026-06-17', $this->trip->days()->where('day_number', 3)->first()->date->toDateString());
    }

    public function test_map_shows_only_own_trips_and_places(): void
    {
        $day = $this->trip->days()->first();
        $day->addPlace(Place::factory()->create(), '10:00');
        $this->user->favoritePlaces()->attach(Place::factory()->create());

        $stranger = User::factory()->create();
        Trip::factory()->for($stranger)->create()->days()->first()->addPlace(Place::factory()->create());
        $stranger->favoritePlaces()->attach(Place::factory()->create());

        $this->actingAs($this->user)
            ->get('/interactive-map')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('InteractiveMap')
                ->has('trips', 1)
                ->has('trips.0.days', 3)
                ->has('categories', PlaceCategory::count())
                ->has('places', 2)
                ->where('places.0.visits.0.dayNumber', 1)
                ->where('places.0.visits.0.startTime', '10:00')
                ->where('places.1.isFavorite', true)
                ->has('places.1.visits', 0));
    }

    public function test_new_place_can_be_added_to_a_trip_day(): void
    {
        $secondDay = $this->trip->days()->where('day_number', 2)->first();
        $secondDay->addPlace(Place::factory()->create());

        $this->actingAs($this->user)
            ->from('/interactive-map')
            ->post('/interactive-map/places', $this->placePayload([
                'destination' => 'trip',
                'trip_day_id' => $secondDay->id,
                'start_time' => '12:30',
            ]))
            ->assertRedirect('/interactive-map')
            ->assertSessionHasNoErrors();

        $place = Place::firstWhere('name', 'Смотровая площадка');
        $this->assertSame($this->user->id, $place->user_id);

        $visit = $place->tripPlaces()->first();
        $this->assertSame($secondDay->id, $visit->trip_day_id);
        $this->assertSame($this->trip->id, $visit->trip_id);
        $this->assertSame(2, $visit->visit_order);
        $this->assertStringStartsWith('12:30', $visit->start_time);
    }

    public function test_new_place_can_be_saved_to_favorites_without_a_trip(): void
    {
        $this->actingAs($this->user)
            ->post('/interactive-map/places', $this->placePayload(['destination' => 'favorite']))
            ->assertSessionHasNoErrors();

        $place = Place::firstWhere('name', 'Смотровая площадка');
        $this->assertTrue($this->user->favoritePlaces()->whereKey($place->id)->exists());
        $this->assertSame(0, $place->tripPlaces()->count());
    }

    public function test_place_validation(): void
    {
        $this->actingAs($this->user)
            ->post('/interactive-map/places', [
                'name' => '',
                'category_id' => 999,
                'latitude' => 120,
                'longitude' => 49,
                'destination' => 'trip',
                'start_time' => '25:99',
            ])
            ->assertSessionHasErrors(['name', 'category_id', 'latitude', 'trip_day_id', 'start_time']);

        $this->assertSame(0, Place::count());
    }

    public function test_place_cannot_be_added_to_someone_elses_trip(): void
    {
        $foreignDay = Trip::factory()->create()->days()->first();

        $this->actingAs($this->user)
            ->post('/interactive-map/places', $this->placePayload([
                'destination' => 'trip',
                'trip_day_id' => $foreignDay->id,
            ]))
            ->assertSessionHasErrors('trip_day_id');

        $this->actingAs($this->user)
            ->post('/places/'.Place::factory()->create()->id.'/trip-places', ['trip_day_id' => $foreignDay->id])
            ->assertSessionHasErrors('trip_day_id');

        $this->assertSame(0, $foreignDay->tripPlaces()->count());
    }

    public function test_favorite_place_can_be_added_to_a_trip(): void
    {
        $place = Place::factory()->create();
        $this->user->favoritePlaces()->attach($place);
        $day = $this->trip->days()->first();

        $this->actingAs($this->user)
            ->post("/places/{$place->id}/trip-places", ['trip_day_id' => $day->id, 'start_time' => '09:15'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($day->tripPlaces()->where('place_id', $place->id)->exists());
    }

    public function test_favorite_can_be_toggled_and_unused_place_is_deleted(): void
    {
        $place = Place::factory()->create();

        $this->actingAs($this->user)->post("/places/{$place->id}/favorite");
        $this->actingAs($this->user)->post("/places/{$place->id}/favorite");
        $this->assertSame(1, $this->user->favoritePlaces()->count());

        $this->actingAs($this->user)->delete("/places/{$place->id}/favorite");
        $this->assertModelMissing($place);
    }

    public function test_removing_from_favorites_keeps_a_place_that_is_planned(): void
    {
        $place = Place::factory()->create();
        $this->user->favoritePlaces()->attach($place);
        $this->trip->days()->first()->addPlace($place);

        $this->actingAs($this->user)->delete("/places/{$place->id}/favorite");

        $this->assertModelExists($place);
    }

    public function test_trip_place_can_be_removed_only_by_trip_owner(): void
    {
        $place = Place::factory()->create();
        $visit = $this->trip->days()->first()->addPlace($place);

        $this->actingAs(User::factory()->create())
            ->delete("/trip-places/{$visit->id}")
            ->assertForbidden();
        $this->assertModelExists($visit);

        $this->actingAs($this->user)->delete("/trip-places/{$visit->id}");
        $this->assertModelMissing($visit);
        $this->assertModelMissing($place);
    }
}
