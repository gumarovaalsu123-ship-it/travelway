<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Server side of the Overpass hints: saving a found place into the day plan.
 * Overpass itself is called only by the browser, so it is not used here.
 */
class OverpassHintsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    private TripDay $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->trip = Trip::factory()->for($this->user)->create([
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth()->addDay(),
        ]);
        $this->day = $this->trip->days()->where('day_number', 1)->sole();
    }

    private function url(?TripDay $day = null): string
    {
        $day ??= $this->day;

        return "/my-trips/{$day->trip_id}/days/{$day->id}/places";
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Памятник Мусе Джалилю',
            'category_id' => PlaceCategory::where('slug', 'attraction')->value('id'),
            'address' => null,
            'latitude' => 55.7986,
            'longitude' => 49.1063,
            ...$overrides,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function dayPlaceNames(): array
    {
        return $this->day->tripPlaces()->with('place')->get()->pluck('place.name')->all();
    }

    public function test_plan_page_gives_coordinates_and_categories_for_hints(): void
    {
        $place = Place::factory()->create(['latitude' => 55.79, 'longitude' => 49.1]);
        $this->day->addPlace($place);

        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/plan")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.places.0.lat', 55.79)
                ->where('selectedDay.places.0.lng', 49.1)
                ->has('categories', PlaceCategory::count()));
    }

    public function test_found_place_is_inserted_between_two_places(): void
    {
        $first = $this->day->addPlace(Place::factory()->create(['name' => 'Кремль']));
        $second = $this->day->addPlace(Place::factory()->create(['name' => 'Озеро Кабан']));
        $this->day->addPlace(Place::factory()->create(['name' => 'Театр']));
        // Старый расчёт: после вставки отрезки соединяли бы не те места.
        $second->update(['travel_minutes' => 15, 'calculated_mode' => 'walk']);

        $this->actingAs($this->user)
            ->from("/my-trips/{$this->trip->id}/plan")
            ->post($this->url(), $this->payload(['after_trip_place_id' => $first->id]))
            ->assertRedirect("/my-trips/{$this->trip->id}/plan")
            ->assertSessionHasNoErrors();

        $this->assertSame(['Кремль', 'Памятник Мусе Джалилю', 'Озеро Кабан', 'Театр'], $this->dayPlaceNames());
        $this->assertSame([1, 2, 3, 4], $this->day->tripPlaces()->pluck('visit_order')->all());
        $this->assertNull($second->fresh()->travel_minutes);

        $place = Place::firstWhere('name', 'Памятник Мусе Джалилю');
        $this->assertSame($this->user->id, $place->user_id);
        $this->assertSame($this->trip->id, $place->tripPlaces()->sole()->trip_id);
    }

    public function test_insertion_after_the_last_place_and_with_gaps_in_numbering(): void
    {
        // Пропуски в нумерации (после удаления места) не мешают: важен только порядок чисел.
        $first = $this->day->addPlace(Place::factory()->create(['name' => 'Кремль']));
        $last = $this->day->addPlace(Place::factory()->create(['name' => 'Театр']));
        $last->update(['visit_order' => 5]);

        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['name' => 'Кафе', 'after_trip_place_id' => $last->id]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['name' => 'Смотровая', 'after_trip_place_id' => $first->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['Кремль', 'Смотровая', 'Театр', 'Кафе'], $this->dayPlaceNames());
    }

    public function test_without_anchor_the_place_goes_to_the_end_of_the_day(): void
    {
        $this->day->addPlace(Place::factory()->create(['name' => 'Кремль']));

        $this->actingAs($this->user)
            ->post($this->url(), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(['Кремль', 'Памятник Мусе Джалилю'], $this->dayPlaceNames());
    }

    public function test_found_place_is_validated(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), [
                'name' => '',
                'category_id' => 999,
                'latitude' => 120,
                'longitude' => 'восток',
                'after_trip_place_id' => 'abc',
            ])
            ->assertSessionHasErrors(['name', 'category_id', 'latitude', 'longitude', 'after_trip_place_id']);

        $this->assertSame(0, Place::count());
    }

    public function test_anchor_must_be_a_place_of_the_same_day(): void
    {
        $otherDay = $this->trip->days()->where('day_number', 2)->sole();
        $otherDayPlace = $otherDay->addPlace(Place::factory()->create());

        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['after_trip_place_id' => $otherDayPlace->id]))
            ->assertSessionHasErrors('after_trip_place_id');

        $this->assertSame(0, $this->day->tripPlaces()->count());
        $this->assertSame(1, $otherDay->tripPlaces()->count());
    }

    public function test_stranger_cannot_add_a_place_to_someone_elses_trip(): void
    {
        $this->actingAs(User::factory()->create())
            ->post($this->url(), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Place::count());
    }

    public function test_day_of_another_trip_gives_not_found(): void
    {
        $ownOtherTrip = Trip::factory()->for($this->user)->create();
        $foreignDay = $ownOtherTrip->days()->first();

        // День другой поездки под адресом этой поездки: scopeBindings не находит его.
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/days/{$foreignDay->id}/places", $this->payload())
            ->assertNotFound();
    }

    public function test_accommodation_page_gives_categories_for_saving_to_favorites(): void
    {
        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/accommodation")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('categories', PlaceCategory::count())
                ->has('categories.0', fn (AssertableInertia $category) => $category->hasAll(['id', 'slug'])));
    }

    public function test_accommodation_page_gives_trip_days_for_adding_found_places_to_the_plan(): void
    {
        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/accommodation")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('days', 2)
                ->where('days.0.id', $this->day->id)
                ->where('days.0.number', 1)
                ->where('days.0.date', $this->day->date->toDateString())
                ->where('days.1.number', 2));
    }

    public function test_place_found_near_lodging_goes_to_the_end_of_the_chosen_day(): void
    {
        $secondDay = $this->trip->days()->where('day_number', 2)->sole();
        $secondDay->addPlace(Place::factory()->create(['name' => 'Кремль']));

        // «Что рядом с жильём?» отправляет то же, что подсказки плана, но без after_trip_place_id.
        $this->actingAs($this->user)
            ->post($this->url($secondDay), $this->payload(['name' => 'Пятёрочка', 'category_id' => PlaceCategory::where('slug', 'shop')->value('id')]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['Кремль', 'Пятёрочка'],
            $secondDay->tripPlaces()->with('place')->get()->pluck('place.name')->all(),
        );
        $this->assertDatabaseHas('places', ['name' => 'Пятёрочка', 'user_id' => $this->user->id]);
    }
}
