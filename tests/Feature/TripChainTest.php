<?php

namespace Tests\Feature;

use App\Models\Transport;
use App\Models\Trip;
use App\Models\User;
use App\Services\TripReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Chain of trips (one trip = one city): the "onward" ride of a trip leads to the next trip.
 */
class TripChainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $kazan;

    protected function setUp(): void
    {
        parent::setUp();

        // При создании поездки ищется обложка в Википедии (TripCoverTest) — здесь отвечаем «не найдено».
        Http::preventStrayRequests();
        Http::fake(['*.wikipedia.org/*' => Http::response([], 404)]);

        $this->user = User::factory()->create();
        $this->kazan = Trip::factory()->for($this->user)->create([
            'destination' => 'Казань',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-17',
        ]);
    }

    private function addOnwardRide(Trip $trip): Transport
    {
        return Transport::factory()->for($trip)->create([
            'type' => 'train',
            'direction' => 'onward',
            'departure_place' => 'Казань',
            'arrival_place' => 'Нижний Новгород',
            'departure_at' => '2026-06-17 23:00',
            'arrival_at' => '2026-06-18 06:00',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function nextTripPayload(array $overrides = []): array
    {
        return [
            'title' => 'Нижний Новгород',
            'destination' => 'Нижний Новгород',
            'start_date' => '2026-06-18',
            'end_date' => '2026-06-20',
            'previous_trip_id' => $this->kazan->id,
            ...$overrides,
        ];
    }

    private function wayThereIsReady(Trip $trip): bool
    {
        $hints = array_column((new TripReadiness($trip->fresh()))->step('transport')['hints'], 'text');

        return ! in_array('Добавьте дорогу туда', $hints, true);
    }

    public function test_transport_page_offers_next_trip_after_onward_ride(): void
    {
        $this->addOnwardRide($this->kazan);

        $this->actingAs($this->user)
            ->get("/my-trips/{$this->kazan->id}/transport")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onwardCity', 'Нижний Новгород'));
    }

    public function test_create_form_is_prefilled_from_onward_ride(): void
    {
        $this->addOnwardRide($this->kazan);

        $this->actingAs($this->user)
            ->get("/my-trips/create?previous={$this->kazan->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('previousTrip.id', $this->kazan->id)
                ->where('previousTrip.destination', 'Нижний Новгород')
                ->where('previousTrip.startDate', '2026-06-18'));
    }

    public function test_next_trip_is_created_linked_to_previous(): void
    {
        $this->actingAs($this->user)->post('/my-trips', $this->nextTripPayload())->assertSessionHasNoErrors();

        $next = Trip::query()->where('destination', 'Нижний Новгород')->firstOrFail();

        $this->assertTrue($next->previousTrip->is($this->kazan));
        $this->assertTrue($this->kazan->nextTrip->is($next));

        // Шапка поездки показывает соседние города, а предложение на «Транспорте» пропадает.
        $this->actingAs($this->user)
            ->get("/my-trips/{$next->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trip.previousTrip.destination', 'Казань')
                ->where('trip.nextTrip', null));

        $this->addOnwardRide($this->kazan);
        $this->actingAs($this->user)
            ->get("/my-trips/{$this->kazan->id}/transport")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onwardCity', null));
    }

    public function test_previous_trip_of_another_user_is_rejected(): void
    {
        $strangerTrip = Trip::factory()->create();

        $this->actingAs($this->user)
            ->post('/my-trips', $this->nextTripPayload(['previous_trip_id' => $strangerTrip->id]))
            ->assertSessionHasErrors('previous_trip_id');

        $this->assertDatabaseMissing('trips', ['destination' => 'Нижний Новгород']);
    }

    public function test_previous_trip_can_have_only_one_next_trip(): void
    {
        Trip::factory()->for($this->user)->create(['previous_trip_id' => $this->kazan->id]);

        $this->actingAs($this->user)
            ->post('/my-trips', $this->nextTripPayload())
            ->assertSessionHasErrors('previous_trip_id');
    }

    public function test_onward_ride_of_previous_trip_counts_as_way_there(): void
    {
        $next = Trip::factory()->for($this->user)->create(['previous_trip_id' => $this->kazan->id]);

        $this->assertFalse($this->wayThereIsReady($next));

        $this->addOnwardRide($this->kazan);

        $this->assertTrue($this->wayThereIsReady($next));
    }

    public function test_deleting_previous_trip_unlinks_next_and_it_needs_its_own_way_there(): void
    {
        $this->addOnwardRide($this->kazan);
        $next = Trip::factory()->for($this->user)->create(['previous_trip_id' => $this->kazan->id]);
        $this->assertTrue($this->wayThereIsReady($next));

        $this->actingAs($this->user)->delete("/my-trips/{$this->kazan->id}")->assertRedirect('/my-trips');

        $this->assertNull($next->fresh()->previous_trip_id);
        $this->assertFalse($this->wayThereIsReady($next));
    }
}
