<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TripTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-10');
        $this->user = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Выходные в Казани',
            'destination' => 'Казань',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-17',
            'is_private' => true,
            ...$overrides,
        ];
    }

    public function test_guest_cannot_see_trips(): void
    {
        $this->get('/my-trips')->assertRedirect('/login');
    }

    public function test_index_lists_only_own_trips_with_computed_status(): void
    {
        Trip::factory()->for($this->user)->create(['title' => 'Сейчас', 'start_date' => '2026-06-09', 'end_date' => '2026-06-12']);
        Trip::factory()->for($this->user)->create(['title' => 'Прошлая', 'start_date' => '2026-05-01', 'end_date' => '2026-05-03']);
        Trip::factory()->for($this->user)->create(['title' => 'Черновик', 'start_date' => null, 'end_date' => null, 'days_count' => 4]);
        Trip::factory()->create(['title' => 'Чужая']);

        $this->actingAs($this->user)
            ->get('/my-trips')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Index')
                ->has('trips', 3)
                ->where('trips', fn ($trips) => collect($trips)->pluck('status', 'title')->all() === [
                    'Сейчас' => 'current',
                    'Прошлая' => 'completed',
                    'Черновик' => 'draft',
                ])
                ->where('featured.isCurrent', true)
                ->where('featured.dayNumber', 2));
    }

    public function test_progress_counts_days_that_have_places(): void
    {
        $trip = Trip::factory()->for($this->user)->create(['start_date' => '2026-06-15', 'end_date' => '2026-06-18']);
        $trip->days()->first()->addPlace(Place::factory()->create());

        $this->actingAs($this->user)
            ->get('/my-trips')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trips.0.progress', 25)
                ->where('trips.0.placesCount', 1)
                ->where('featured.placeNames', fn ($names) => count($names) === 1));
    }

    public function test_trip_with_dates_is_created_with_a_day_per_date(): void
    {
        $this->actingAs($this->user)
            ->post('/my-trips', $this->payload())
            ->assertRedirect('/interactive-map')
            ->assertSessionHasNoErrors();

        $trip = $this->user->trips()->first();
        $this->assertSame(3, $trip->days_count);
        $this->assertFalse($trip->is_public);
        $this->assertSame(['2026-06-15', '2026-06-16', '2026-06-17'], $trip->days->map(fn ($day) => $day->date->toDateString())->all());
    }

    public function test_draft_trip_is_created_from_number_of_days(): void
    {
        $this->actingAs($this->user)
            ->post('/my-trips', $this->payload(['start_date' => '', 'end_date' => '', 'days_count' => 5, 'is_private' => false]))
            ->assertSessionHasNoErrors();

        $trip = $this->user->trips()->first();
        $this->assertSame('draft', $trip->status);
        $this->assertTrue($trip->is_public);
        $this->assertSame(5, $trip->days()->count());
        $this->assertNull($trip->days()->first()->date);
    }

    public function test_trip_validation(): void
    {
        $this->actingAs($this->user)
            ->post('/my-trips', $this->payload(['title' => 'Ка', 'destination' => '', 'end_date' => '2026-06-14']))
            ->assertSessionHasErrors(['title', 'destination', 'end_date']);

        $this->actingAs($this->user)
            ->post('/my-trips', $this->payload(['start_date' => '', 'end_date' => '']))
            ->assertSessionHasErrors('days_count');

        $this->actingAs($this->user)
            ->post('/my-trips', $this->payload(['end_date' => '2026-09-01']))
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, Trip::count());
    }

    public function test_shortening_a_trip_removes_extra_days_and_their_places(): void
    {
        $trip = Trip::factory()->for($this->user)->create(['start_date' => '2026-06-15', 'end_date' => '2026-06-17']);
        $lastDayPlace = Place::factory()->create();
        $favoritePlace = Place::factory()->create();
        $this->user->favoritePlaces()->attach($favoritePlace);
        $trip->days()->where('day_number', 3)->first()->addPlace($lastDayPlace);
        $trip->days()->where('day_number', 3)->first()->addPlace($favoritePlace);
        $firstDayVisit = $trip->days()->first()->addPlace(Place::factory()->create());

        $this->actingAs($this->user)
            ->put("/my-trips/{$trip->id}", $this->payload(['start_date' => '2026-07-01', 'end_date' => '2026-07-02']))
            ->assertRedirect('/my-trips');

        $trip->refresh();
        $this->assertSame(2, $trip->days_count);
        $this->assertSame('2026-07-01', $trip->days()->first()->date->toDateString());
        $this->assertModelExists($firstDayVisit);
        $this->assertModelMissing($lastDayPlace);
        $this->assertModelExists($favoritePlace);
    }

    public function test_draft_can_get_dates_later(): void
    {
        $trip = Trip::factory()->for($this->user)->create(['start_date' => null, 'end_date' => null, 'days_count' => 2]);
        $visit = $trip->days()->first()->addPlace(Place::factory()->create());

        $this->actingAs($this->user)
            ->put("/my-trips/{$trip->id}", $this->payload(['start_date' => '2026-08-01', 'end_date' => '2026-08-02']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-08-01', $visit->day->fresh()->date->toDateString());
    }

    public function test_trip_can_be_deleted_with_its_unused_places(): void
    {
        $trip = Trip::factory()->for($this->user)->create();
        $place = Place::factory()->create();
        $trip->days()->first()->addPlace($place);

        $this->actingAs($this->user)
            ->delete("/my-trips/{$trip->id}")
            ->assertRedirect('/my-trips');

        $this->assertModelMissing($trip);
        $this->assertModelMissing($place);
    }

    public function test_someone_elses_trip_cannot_be_edited_or_deleted(): void
    {
        $trip = Trip::factory()->create();

        $this->actingAs($this->user)->get("/my-trips/{$trip->id}/edit")->assertForbidden();
        $this->actingAs($this->user)->put("/my-trips/{$trip->id}", $this->payload())->assertForbidden();
        $this->actingAs($this->user)->delete("/my-trips/{$trip->id}")->assertForbidden();

        $this->assertModelExists($trip);
    }
}
