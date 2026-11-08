<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'name' => 'Lisboa Pessoa Hotel',
            'address' => 'R. da Oliveira ao Carmo 8, Lisboa',
            'check_in' => '2026-06-15T15:00',
            'check_out' => '2026-06-19T12:00',
            'booking_info' => 'Бронь LP-240615',
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
    }

    public function test_only_name_is_required(): void
    {
        $this->actingAs($this->user)
            ->post($this->url(), ['name' => 'Квартира у друзей'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->trip->accommodations()->sole()->check_in);
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
                'check_out' => 'Выезд не может быть раньше заезда.',
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
}
