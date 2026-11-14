<?php

namespace Tests\Feature;

use App\Models\Transport;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TripTransportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // 15–17 июня: дорога туда — 14–16 июня, обратно и дальше — 16–18 июня (±1 день).
        $this->trip = Trip::factory()->for($this->user)->create(['start_date' => '2026-06-15', 'end_date' => '2026-06-17']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'plane',
            'direction' => 'to',
            'departure_place' => 'Москва, Шереметьево',
            'arrival_place' => 'Казань',
            'departure_at' => '2026-06-15T13:15',
            'arrival_at' => '2026-06-15T15:00',
            'is_booked' => true,
            'note' => 'Багаж 23 кг',
            ...$overrides,
        ];
    }

    /**
     * Время дороги обратно в последний день поездки.
     *
     * @return array{departure_at: string, arrival_at: string}
     */
    private function wayBackTimes(): array
    {
        return ['departure_at' => '2026-06-17T18:00', 'arrival_at' => '2026-06-17T20:00'];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get("/my-trips/{$this->trip->id}/transport")->assertRedirect('/login');
    }

    public function test_index_lists_transport_sorted_by_departure_with_untimed_last(): void
    {
        Transport::factory()->for($this->trip)->create(['departure_place' => 'Без времени', 'departure_at' => null, 'arrival_at' => null]);
        Transport::factory()->for($this->trip)->create(['departure_place' => 'Второй', 'departure_at' => '2026-06-20 10:00']);
        Transport::factory()->for($this->trip)->create(['departure_place' => 'Первый', 'departure_at' => '2026-06-15 08:30']);
        Transport::factory()->create(['departure_place' => 'Чужой']);

        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/transport")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Transport')
                ->where('trip.id', $this->trip->id)
                ->has('transports', 3)
                ->where('transports.0.departurePlace', 'Первый')
                ->where('transports.0.departureAt', '2026-06-15T08:30')
                ->where('transports.1.departurePlace', 'Второй')
                ->where('transports.2.departurePlace', 'Без времени'));
    }

    public function test_owner_can_add_transport(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload())
            ->assertRedirect("/my-trips/{$this->trip->id}/transport");

        $this->assertDatabaseHas('transport', [
            'trip_id' => $this->trip->id,
            'type' => 'plane',
            'direction' => 'to',
            'departure_place' => 'Москва, Шереметьево',
            'is_booked' => true,
        ]);
    }

    public function test_direction_is_required_and_must_be_from_the_list(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['direction' => 'sideways']))
            ->assertSessionHasErrors(['direction' => 'Направление может быть только «Туда», «Обратно» или «Дальше».']);

        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['direction' => '']))
            ->assertSessionHasErrors(['direction' => 'Укажите направление: туда, обратно или дальше.']);

        $this->assertDatabaseCount('transport', 0);
    }

    public function test_own_car_needs_no_ticket(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['type' => 'own_car', 'direction' => 'back', 'is_booked' => true, ...$this->wayBackTimes()]))
            ->assertSessionHasNoErrors();

        $transport = $this->trip->transports()->sole();
        $this->assertSame('back', $transport->direction);
        $this->assertFalse($transport->is_booked);
        $this->assertFalse($transport->needsTicket());
    }

    public function test_index_shows_direction_and_ticket_status(): void
    {
        Transport::factory()->for($this->trip)->create(['type' => 'train', 'direction' => 'onward', 'is_booked' => true, 'departure_at' => '2026-06-15 08:00']);
        // Запись, сохранённая до появления направлений: direction = null.
        Transport::factory()->for($this->trip)->create(['type' => 'own_car', 'direction' => null, 'is_booked' => false, 'departure_at' => '2026-06-16 08:00']);

        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/transport")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('directions', Transport::Directions)
                ->where('transports.0.direction', 'onward')
                ->where('transports.0.isBooked', true)
                ->where('transports.0.needsTicket', true)
                ->where('transports.1.direction', null)
                ->where('transports.1.needsTicket', false));
    }

    public function test_times_are_optional(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['departure_at' => '', 'arrival_at' => '']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('transport', ['trip_id' => $this->trip->id, 'departure_at' => null, 'arrival_at' => null]);
    }

    public function test_validation_errors(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload([
                'type' => 'rocket',
                'departure_place' => '',
                'arrival_place' => '',
            ]))
            ->assertSessionHasErrors(['type', 'departure_place', 'arrival_place']);

        $this->assertDatabaseCount('transport', 0);
    }

    public function test_arrival_cannot_be_earlier_than_departure(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['arrival_at' => '2026-06-15T12:00']))
            ->assertSessionHasErrors(['arrival_at' => 'Прибытие не может быть раньше отправления.']);
    }

    public function test_owner_can_update_transport(): void
    {
        $transport = Transport::factory()->for($this->trip)->create();

        $this->actingAs($this->user)
            ->put("/my-trips/{$this->trip->id}/transport/{$transport->id}", $this->payload(['type' => 'train', 'direction' => 'back', 'is_booked' => false, 'arrival_place' => 'Сочи', ...$this->wayBackTimes()]))
            ->assertRedirect("/my-trips/{$this->trip->id}/transport");

        $transport->refresh();
        $this->assertSame('train', $transport->type);
        $this->assertSame('Сочи', $transport->arrival_place);
        $this->assertSame('back', $transport->direction);
        $this->assertFalse($transport->is_booked);
    }

    public function test_owner_can_delete_transport(): void
    {
        $transport = Transport::factory()->for($this->trip)->create();

        $this->actingAs($this->user)
            ->delete("/my-trips/{$this->trip->id}/transport/{$transport->id}")
            ->assertRedirect("/my-trips/{$this->trip->id}/transport");

        $this->assertModelMissing($transport);
    }

    public function test_transport_is_deleted_with_its_trip(): void
    {
        $transport = Transport::factory()->for($this->trip)->create();

        $this->trip->delete();

        $this->assertModelMissing($transport);
    }

    public function test_stranger_cannot_see_or_change_transport_of_someone_elses_trip(): void
    {
        $transport = Transport::factory()->for($this->trip)->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/my-trips/{$this->trip->id}/transport")->assertForbidden();
        $this->actingAs($stranger)->post("/my-trips/{$this->trip->id}/transport", $this->payload())->assertForbidden();
        $this->actingAs($stranger)->put("/my-trips/{$this->trip->id}/transport/{$transport->id}", $this->payload())->assertForbidden();
        $this->actingAs($stranger)->delete("/my-trips/{$this->trip->id}/transport/{$transport->id}")->assertForbidden();

        $this->assertModelExists($transport);
        $this->assertDatabaseCount('transport', 1);
    }

    public function test_record_of_another_trip_cannot_be_changed_through_own_trip(): void
    {
        $foreignTransport = Transport::factory()->create();

        $this->actingAs($this->user)
            ->put("/my-trips/{$this->trip->id}/transport/{$foreignTransport->id}", $this->payload())
            ->assertNotFound();

        $this->actingAs($this->user)
            ->delete("/my-trips/{$this->trip->id}/transport/{$foreignTransport->id}")
            ->assertNotFound();

        $this->assertModelExists($foreignTransport);
    }

    // ——— Даты дороги и даты поездки ———

    public function test_way_there_must_be_around_the_trip_start(): void
    {
        $message = 'Дорога туда должна быть с 14 июня по 16 июня: поездка начинается 15 июня, допускается день до или после.';

        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['departure_at' => '2026-06-12T08:00', 'arrival_at' => '2026-06-12T10:00']))
            ->assertSessionHasErrors(['departure_at' => $message, 'arrival_at' => $message]);

        // Прибыть туда позже чем через день после начала тоже нельзя.
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['departure_at' => '2026-06-16T20:00', 'arrival_at' => '2026-06-17T08:00']))
            ->assertSessionHasErrors(['arrival_at' => $message])
            ->assertSessionDoesntHaveErrors('departure_at');

        $this->assertDatabaseCount('transport', 0);
    }

    public function test_night_train_the_day_before_the_start_is_allowed(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['type' => 'train', 'departure_at' => '2026-06-14T22:00', 'arrival_at' => '2026-06-15T07:00']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transport', 1);
    }

    public function test_way_back_and_onward_must_be_around_the_trip_end(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['direction' => 'back', 'departure_at' => '2026-06-15T18:00', 'arrival_at' => '2026-06-15T20:00']))
            ->assertSessionHasErrors([
                'departure_at' => 'Дорога обратно должна быть с 16 июня по 18 июня: поездка заканчивается 17 июня, допускается день до или после.',
            ]);

        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['direction' => 'onward', 'departure_at' => '2026-06-18T22:00', 'arrival_at' => '2026-06-19T09:00']))
            ->assertSessionHasErrors([
                'arrival_at' => 'Переезд дальше должен быть с 16 июня по 18 июня: поездка заканчивается 17 июня, допускается день до или после.',
            ]);

        $this->assertDatabaseCount('transport', 0);
    }

    public function test_draft_without_dates_only_checks_that_arrival_is_not_before_departure(): void
    {
        $draft = Trip::factory()->for($this->user)->create(['start_date' => null, 'end_date' => null, 'days_count' => 3]);

        $this->actingAs($this->user)
            ->post("/my-trips/{$draft->id}/transport", $this->payload(['departure_at' => '2026-09-01T10:00', 'arrival_at' => '2026-09-01T12:00']))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->post("/my-trips/{$draft->id}/transport", $this->payload(['departure_at' => '2026-09-01T10:00', 'arrival_at' => '2026-09-01T09:00']))
            ->assertSessionHasErrors(['arrival_at' => 'Прибытие не может быть раньше отправления.']);
    }

    public function test_page_gets_date_limits_and_suggested_departure_by_direction(): void
    {
        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/transport")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dateLimits.to', ['min' => '2026-06-14T00:00', 'max' => '2026-06-16T23:59', 'departure' => '2026-06-15T08:00'])
                ->where('dateLimits.back', ['min' => '2026-06-16T00:00', 'max' => '2026-06-18T23:59', 'departure' => '2026-06-17T18:00'])
                ->where('dateLimits.onward', ['min' => '2026-06-16T00:00', 'max' => '2026-06-18T23:59', 'departure' => '2026-06-17T18:00']));

        $draft = Trip::factory()->for($this->user)->create(['start_date' => null, 'end_date' => null, 'days_count' => 3]);

        $this->actingAs($this->user)
            ->get("/my-trips/{$draft->id}/transport")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('dateLimits', null));
    }

    public function test_booking_number_is_neither_saved_nor_shown(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/transport", $this->payload(['booking_info' => 'SU 1234']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('transport', ['trip_id' => $this->trip->id, 'booking_info' => null]);

        // Старая запись с номером брони: колонка в базе осталась, но на страницу номер не уходит.
        $this->trip->transports()->update(['booking_info' => 'Старая бронь']);

        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/transport")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('transports.0', fn (AssertableInertia $transport) => $transport
                    ->missing('bookingInfo')
                    ->etc()));
    }
}
