<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TripAccommodationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        // Поездка в июне ещё впереди: завершённую поездку менять нельзя (TripPolicy::update).
        Carbon::setTestNow('2026-06-01');
        $this->user = User::factory()->create();
        // 15–20 июня: 6 дней, 5 ночей (с 15 на 16 … с 19 на 20).
        $this->trip = Trip::factory()->for($this->user)->create(['start_date' => '2026-06-15', 'end_date' => '2026-06-20']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'hotel',
            'name' => 'Lisboa Pessoa Hotel',
            'address' => 'R. da Oliveira ao Carmo 8, Lisboa',
            'check_in' => '2026-06-15T15:00',
            'check_out' => '2026-06-19T12:00',
            'booking_info' => 'Бронь LP-240615',
            'is_booked' => true,
            'latitude' => 38.7118,
            'longitude' => -9.1405,
            'note' => 'Бесплатная отмена до 12 июня',
            ...$overrides,
        ];
    }

    private function url(?Accommodation $accommodation = null, ?Trip $trip = null): string
    {
        $url = '/my-trips/'.($trip ?? $this->trip)->id.'/accommodation';

        return $accommodation ? "{$url}/{$accommodation->id}" : $url;
    }

    public function test_tab_shows_accommodations_with_nights_and_missing_nights(): void
    {
        Accommodation::factory()->for($this->trip)->create([
            'name' => 'Отель',
            'check_in' => '2026-06-15 15:00',
            'check_out' => '2026-06-17 12:00',
        ]);
        Accommodation::factory()->for($this->trip)->create([
            'name' => 'Без дат',
            'check_in' => null,
            'check_out' => null,
        ]);
        Accommodation::factory()->create(['name' => 'Чужое']);

        $this->actingAs($this->user)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Accommodation')
                ->where('trip.id', $this->trip->id)
                ->has('accommodations', 2)
                ->where('accommodations.0.name', 'Отель')
                ->where('accommodations.0.checkIn', '2026-06-15T15:00')
                ->where('accommodations.0.nights', 2)
                ->where('accommodations.1.name', 'Без дат')
                ->where('accommodations.1.nights', null)
                ->where('nights.total', 5)
                ->where('nights.covered', 2)
                ->where('nights.missing', [['from' => '2026-06-17', 'to' => '2026-06-20']]));
    }

    public function test_trip_without_dates_has_no_night_coverage(): void
    {
        $draft = Trip::factory()->for($this->user)->create(['start_date' => null, 'end_date' => null, 'days_count' => 3]);

        $this->actingAs($this->user)
            ->get($this->url(trip: $draft))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nights', ['total' => 2, 'covered' => null, 'missing' => []]));
    }

    public function test_owner_can_add_accommodation(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), $this->payload())
            ->assertRedirect($this->url());

        $accommodation = $this->trip->accommodations()->sole();
        $this->assertSame('Lisboa Pessoa Hotel', $accommodation->name);
        $this->assertSame('2026-06-15 15:00', $accommodation->check_in->format('Y-m-d H:i'));
        $this->assertSame(4, $accommodation->nights());
        $this->assertEqualsWithDelta(38.7118, $accommodation->latitude, 0.0001);
        $this->assertSame('hotel', $accommodation->type);
        $this->assertTrue($accommodation->is_booked);
    }

    public function test_only_type_and_name_are_required(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), ['type' => 'apartment', 'name' => 'Квартира на Арбате'])
            ->assertSessionHasNoErrors();

        $accommodation = $this->trip->accommodations()->sole();
        $this->assertNull($accommodation->check_in);
        $this->assertFalse($accommodation->is_booked);
    }

    public function test_type_must_be_from_the_list(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['type' => 'castle']))
            ->assertSessionHasErrors(['type' => 'Выберите тип жилья из списка.']);

        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['type' => '']))
            ->assertSessionHasErrors(['type' => 'Выберите тип жилья.']);

        $this->assertSame(0, Accommodation::count());
    }

    public function test_staying_with_friends_is_never_marked_as_booked(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['type' => 'friends', 'name' => 'У тёти Веры', 'is_booked' => true]))
            ->assertSessionHasNoErrors();

        $accommodation = $this->trip->accommodations()->sole();
        $this->assertSame('friends', $accommodation->type);
        $this->assertFalse($accommodation->is_booked);
        $this->assertFalse($accommodation->needsBooking());
    }

    public function test_tab_shows_type_and_booking_status(): void
    {
        Accommodation::factory()->for($this->trip)->create(['type' => 'hostel', 'is_booked' => false, 'check_in' => '2026-06-15 15:00']);
        Accommodation::factory()->for($this->trip)->create(['type' => 'friends', 'is_booked' => false, 'check_in' => '2026-06-17 15:00']);

        $this->actingAs($this->user)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('types', Accommodation::Types)
                ->where('accommodations.0.type', 'hostel')
                ->where('accommodations.0.isBooked', false)
                ->where('accommodations.0.needsBooking', true)
                ->where('accommodations.1.type', 'friends')
                ->where('accommodations.1.needsBooking', false));
    }

    public function test_validation_errors(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), $this->payload([
                'name' => '',
                'check_in' => '2026-06-18T15:00',
                'check_out' => '2026-06-16T12:00',
                'longitude' => null,
            ]))
            ->assertSessionHasErrors([
                'name' => 'Заполните поле «название».',
                'check_out' => 'Выезд должен быть позже заезда.',
                'longitude',
            ]);

        $this->assertSame(0, Accommodation::count());
    }

    public function test_owner_can_update_accommodation(): void
    {
        $accommodation = Accommodation::factory()->for($this->trip)->create();

        $this->actingAs($this->user)
            ->put($this->url($accommodation), $this->payload(['name' => 'Новое название', 'latitude' => null, 'longitude' => null]))
            ->assertRedirect($this->url());

        $accommodation->refresh();
        $this->assertSame('Новое название', $accommodation->name);
        $this->assertNull($accommodation->latitude);
    }

    public function test_owner_can_delete_accommodation(): void
    {
        $accommodation = Accommodation::factory()->for($this->trip)->create();

        $this->actingAs($this->user)
            ->delete($this->url($accommodation))
            ->assertRedirect($this->url());

        $this->assertModelMissing($accommodation);
    }

    public function test_accommodations_are_deleted_with_the_trip(): void
    {
        $accommodation = Accommodation::factory()->for($this->trip)->create();

        $this->actingAs($this->user)->delete("/my-trips/{$this->trip->id}");

        $this->assertModelMissing($accommodation);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get($this->url())->assertRedirect('/login');
    }

    public function test_someone_elses_trip_is_forbidden(): void
    {
        $otherTrip = Trip::factory()->create();
        $accommodation = Accommodation::factory()->for($otherTrip)->create();

        $this->actingAs($this->user);

        $this->get($this->url(trip: $otherTrip))->assertForbidden();
        $this->post($this->url(trip: $otherTrip), $this->payload())->assertForbidden();
        $this->put($this->url($accommodation, $otherTrip), $this->payload())->assertForbidden();
        $this->delete($this->url($accommodation, $otherTrip))->assertForbidden();

        $this->assertModelExists($accommodation);
        $this->assertSame(1, Accommodation::count());
    }

    public function test_accommodation_of_another_trip_cannot_be_changed_through_own_trip(): void
    {
        $foreign = Accommodation::factory()->create(['name' => 'Чужое']);

        $this->actingAs($this->user);

        $this->put($this->url($foreign), $this->payload())->assertNotFound();
        $this->delete($this->url($foreign))->assertNotFound();

        $this->assertSame('Чужое', $foreign->fresh()->name);
    }

    // ——— Даты жилья и даты поездки ———

    public function test_stay_must_be_within_trip_dates_with_one_day_margin(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['check_in' => '2026-06-13T15:00', 'check_out' => '2026-06-22T12:00']))
            ->assertSessionHasErrors([
                'check_in' => 'Заезд должен быть с 14 июня по 20 июня: поездка с 15 июня по 20 июня.',
                'check_out' => 'Выезд должен быть с 15 июня по 21 июня: поездка с 15 июня по 20 июня.',
            ]);

        $this->assertSame(0, Accommodation::count());
    }

    public function test_arriving_the_evening_before_and_leaving_the_day_after_is_allowed(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['check_in' => '2026-06-14T21:00', 'check_out' => '2026-06-21T10:00']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Accommodation::count());
    }

    public function test_check_out_must_be_later_than_check_in(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), $this->payload(['check_in' => '2026-06-16T12:00', 'check_out' => '2026-06-16T12:00']))
            ->assertSessionHasErrors(['check_out' => 'Выезд должен быть позже заезда.']);
    }

    public function test_draft_without_dates_does_not_limit_the_stay(): void
    {
        $draft = Trip::factory()->for($this->user)->create(['start_date' => null, 'end_date' => null, 'days_count' => 3]);

        $this->actingAs($this->user)
            ->post($this->url(trip: $draft), $this->payload(['check_in' => '2026-09-01T15:00', 'check_out' => '2026-09-03T12:00']))
            ->assertSessionHasNoErrors();
    }

    public function test_first_stay_is_suggested_from_the_trip_start_to_the_trip_end(): void
    {
        $this->actingAs($this->user)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('newDates', ['checkIn' => '2026-06-15T14:00', 'checkOut' => '2026-06-20T12:00'])
                ->where('dateLimits.checkIn', ['min' => '2026-06-14T00:00', 'max' => '2026-06-20T23:59'])
                ->where('dateLimits.checkOut', ['min' => '2026-06-15T00:00', 'max' => '2026-06-21T23:59']));
    }

    public function test_next_stay_is_suggested_from_the_previous_check_out(): void
    {
        Accommodation::factory()->for($this->trip)->create(['check_in' => '2026-06-15 15:00', 'check_out' => '2026-06-18 11:00']);

        $this->actingAs($this->user)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('newDates', ['checkIn' => '2026-06-18T11:00', 'checkOut' => '2026-06-20T12:00']));

        // Последнее жильё уже до конца поездки — выезд не подставляем, его выберет человек.
        Accommodation::factory()->for($this->trip)->create(['check_in' => '2026-06-18 15:00', 'check_out' => '2026-06-20 12:00']);

        $this->actingAs($this->user)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('newDates', ['checkIn' => '2026-06-20T12:00', 'checkOut' => '']));
    }

    public function test_only_hotels_apartments_and_hostels_have_a_photo(): void
    {
        foreach (['hotel', 'apartment', 'hostel', 'friends', 'other'] as $index => $type) {
            Accommodation::factory()->for($this->trip)->create(['type' => $type, 'check_in' => "2026-06-1{$index} 15:00"]);
        }

        $this->actingAs($this->user)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accommodations.0.hasPhoto', true)
                ->where('accommodations.1.hasPhoto', true)
                ->where('accommodations.2.hasPhoto', true)
                ->where('accommodations.3.hasPhoto', false)
                ->where('accommodations.4.hasPhoto', false));
    }
}
