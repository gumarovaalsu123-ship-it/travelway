<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\PackingList;
use App\Models\Place;
use App\Models\Transport;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Three stages of a trip page (Trip::tabs()): planning, current, completed.
 * "Today" is 16 June 13:00: a trip in July is planned, 15–17 June is current, May is completed.
 */
class TripStagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-16 13:00');
        $this->user = User::factory()->create();
    }

    private function tripFrom(string $startDate, string $endDate): Trip
    {
        return Trip::factory()->for($this->user)->create(['start_date' => $startDate, 'end_date' => $endDate]);
    }

    /**
     * Обратный рейс в день $date (по умолчанию — последний день текущей поездки).
     *
     * @return array<string, mixed>
     */
    private function transportPayload(string $date = '2026-06-17'): array
    {
        return [
            'type' => 'plane',
            'direction' => 'back',
            'departure_place' => 'Казань',
            'arrival_place' => 'Москва',
            'departure_at' => "{$date}T19:00",
            'arrival_at' => "{$date}T20:30",
            'is_booked' => true,
            'note' => 'Новый рейс',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accommodationPayload(string $checkIn = '2026-06-16', string $checkOut = '2026-06-17'): array
    {
        return [
            'type' => 'hotel',
            'name' => 'Другой отель',
            'check_in' => "{$checkIn}T15:00",
            'check_out' => "{$checkOut}T12:00",
            'is_booked' => true,
        ];
    }

    public function test_whole_trip_card_leads_to_its_trip(): void
    {
        $trip = $this->tripFrom('2026-07-01', '2026-07-03');

        $this->actingAs($this->user)
            ->get('/my-trips')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Index')
                // По id карточка и большая карточка сверху строят ссылку /my-trips/{id}.
                ->where('trips.0.id', $trip->id)
                ->where('featured.id', $trip->id)
                ->missing('featured.placeNames'));
    }

    public function test_planning_trip_has_every_tab_and_everything_can_be_changed(): void
    {
        $trip = $this->tripFrom('2026-07-01', '2026-07-03');

        $this->actingAs($this->user)
            ->get("/my-trips/{$trip->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Show')
                ->where('trip.stage', Trip::StagePlanning)
                ->where('trip.tabs', [
                    'overview' => true, 'plan' => true, 'transport' => true,
                    'accommodation' => true, 'packing' => true, 'advance' => true, 'settings' => true,
                ]));
    }

    public function test_draft_is_planning_too(): void
    {
        $trip = Trip::factory()->for($this->user)->create(['start_date' => null, 'end_date' => null, 'days_count' => 2]);

        $this->assertSame(Trip::StagePlanning, $trip->stage());
        $this->assertTrue($trip->hasTab('packing'));
    }

    public function test_current_trip_opens_today_overview_without_preparation_tabs(): void
    {
        $trip = $this->tripFrom('2026-06-15', '2026-06-17');
        $today = $trip->days()->where('day_number', 2)->first();
        $done = $today->addPlace(Place::factory()->create(['name' => 'Кремль']), '10:00');
        $done->update(['end_time' => '11:30']);
        $next = $today->addPlace(Place::factory()->create(['name' => 'Обед']), '12:30');
        $next->update(['end_time' => '14:00']);
        $today->addPlace(Place::factory()->create(['name' => 'Набережная']), '16:00');

        $this->actingAs($this->user)
            ->get("/my-trips/{$trip->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Today')
                ->where('trip.stage', Trip::StageCurrent)
                ->where('trip.tabs', [
                    'today' => true, 'plan' => true,
                    'transport' => true, 'accommodation' => true, 'settings' => true,
                ])
                ->where('day.number', 2)
                ->has('places', 3)
                // Сейчас 13:00: «Кремль» закончился в 11:30, «Обед» идёт до 14:00 — он и следующий.
                ->where('nextPlaceId', $next->id));
    }

    public function test_hidden_tabs_of_current_trip_redirect_to_today(): void
    {
        $trip = $this->tripFrom('2026-06-15', '2026-06-17');

        $this->actingAs($this->user)->get("/my-trips/{$trip->id}/packing")->assertRedirect("/my-trips/{$trip->id}");
        $this->get("/my-trips/{$trip->id}/advance")->assertRedirect("/my-trips/{$trip->id}");
    }

    public function test_current_trip_plan_can_still_be_changed(): void
    {
        $trip = $this->tripFrom('2026-06-15', '2026-06-17');
        $day = $trip->days()->first();

        $this->actingAs($this->user)
            ->post('/places/'.Place::factory()->create()->id.'/trip-places', ['trip_day_id' => $day->id])
            ->assertRedirect();

        $this->assertSame(1, $day->tripPlaces()->count());
    }

    public function test_current_trip_transport_and_lodging_can_still_be_changed(): void
    {
        $trip = $this->tripFrom('2026-06-15', '2026-06-17');
        $transport = Transport::factory()->for($trip)->create();
        $accommodation = Accommodation::factory()->for($trip)->create();

        $this->actingAs($this->user);

        // Обратный рейс перенесли.
        $this->put("/my-trips/{$trip->id}/transport/{$transport->id}", $this->transportPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('Новый рейс', $transport->fresh()->note);

        // Добавили ночь в другом отеле.
        $this->put("/my-trips/{$trip->id}/accommodation/{$accommodation->id}", $this->accommodationPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('Другой отель', $accommodation->fresh()->name);
    }

    public function test_completed_trip_has_no_preparation_tab_and_opens_on_plan(): void
    {
        $trip = $this->tripFrom('2026-05-01', '2026-05-03');

        $this->actingAs($this->user)
            ->get("/my-trips/{$trip->id}")
            ->assertRedirect("/my-trips/{$trip->id}/plan");

        $this->get("/my-trips/{$trip->id}/plan")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Plan')
                ->where('trip.stage', Trip::StageCompleted)
                ->where('trip.tabs', [
                    'plan' => false, 'transport' => false,
                    'accommodation' => false, 'packing' => false, 'advance' => false, 'settings' => true,
                ]));
    }

    public function test_completed_trip_tabs_are_read_only(): void
    {
        $trip = $this->tripFrom('2026-05-01', '2026-05-03');

        $this->actingAs($this->user);

        // Смотреть можно все вкладки.
        foreach (['plan', 'transport', 'accommodation', 'packing', 'advance'] as $tab) {
            $this->get("/my-trips/{$trip->id}/{$tab}")->assertOk();
        }

        // План (вместе с картой дня) — только для просмотра.
        $this->get("/my-trips/{$trip->id}/plan")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Trips/Plan')->where('trip.tabs.plan', false));
    }

    public function test_completed_trip_cannot_be_changed(): void
    {
        $trip = $this->tripFrom('2026-05-01', '2026-05-03');
        $day = $trip->days()->first();
        $visit = $day->addPlace(Place::factory()->create());
        $list = PackingList::factory()->for($trip)->create();

        $this->actingAs($this->user);

        $this->post("/my-trips/{$trip->id}/packing-lists", ['title' => 'Одежда'])->assertForbidden();
        $this->patch("/my-trips/{$trip->id}/packing-lists/{$list->id}", ['title' => 'Новое'])->assertForbidden();
        $this->post("/my-trips/{$trip->id}/tasks", ['title' => 'Купить сувениры'])->assertForbidden();
        $this->post('/places/'.Place::factory()->create()->id.'/trip-places', ['trip_day_id' => $day->id])->assertForbidden();
        $this->delete("/trip-places/{$visit->id}")->assertForbidden();

        $this->assertModelExists($visit);
        $this->assertSame(1, $trip->tripPlaces()->count());
    }

    public function test_completed_trip_transport_and_lodging_cannot_be_changed(): void
    {
        $trip = $this->tripFrom('2026-05-01', '2026-05-03');
        $transport = Transport::factory()->for($trip)->create();
        $accommodation = Accommodation::factory()->for($trip)->create();

        $this->actingAs($this->user);

        $this->put("/my-trips/{$trip->id}/transport/{$transport->id}", $this->transportPayload('2026-05-03'))->assertForbidden();
        $this->put("/my-trips/{$trip->id}/accommodation/{$accommodation->id}", $this->accommodationPayload('2026-05-01', '2026-05-03'))->assertForbidden();

        $this->assertNotSame('Новый рейс', $transport->fresh()->note);
        $this->assertNotSame('Другой отель', $accommodation->fresh()->name);
    }

    public function test_completed_trip_settings_can_fix_dates_and_trip_can_be_deleted(): void
    {
        $trip = $this->tripFrom('2026-05-01', '2026-05-03');

        $this->actingAs($this->user)
            ->get("/my-trips/{$trip->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('trip.isCompleted', true));

        // Даты ввели с ошибкой: поездка на самом деле в июле — она снова планируется.
        $this->put("/my-trips/{$trip->id}", [
            'title' => $trip->title,
            'destination' => $trip->destination,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-03',
            'is_private' => true,
        ])->assertRedirect(route('trips.edit', $trip));
        $this->assertSame(Trip::StagePlanning, $trip->fresh()->stage());

        $this->travelTo('2026-08-01');
        $this->delete("/my-trips/{$trip->id}")->assertRedirect('/my-trips');
        $this->assertModelMissing($trip);
    }

    public function test_completed_trips_are_not_offered_on_the_interactive_map(): void
    {
        $this->tripFrom('2026-05-01', '2026-05-03');
        $planned = $this->tripFrom('2026-07-01', '2026-07-03');

        $this->actingAs($this->user)
            ->get('/interactive-map')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('trips', 1)
                ->where('trips.0.id', $planned->id));
    }
}
