<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Place;
use App\Models\Transport;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Публичная ссылка на поездку /t/{token}: открыть и закрыть доступ в «Настройках», просмотр без входа.
 */
class PublicTripLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->trip = Trip::factory()->for($this->owner)->create([
            'title' => 'Отпуск с Машей',
            'description' => 'Секретное описание',
            'destination' => 'Казань',
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth()->addDays(1),
        ]);
    }

    private function share(): string
    {
        $this->actingAs($this->owner)->post("/my-trips/{$this->trip->id}/share")->assertRedirect();
        auth()->logout();

        return $this->trip->refresh()->public_token;
    }

    public function test_owner_opens_access_and_gets_a_long_random_token(): void
    {
        $token = $this->share();

        $this->assertTrue($this->trip->is_public);
        $this->assertSame(40, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $token);

        // У другой поездки — другой токен: он случайный, а не выводится из id.
        $other = Trip::factory()->for($this->owner)->create();
        $other->openPublicAccess();
        $this->assertNotSame($token, $other->public_token);
    }

    public function test_opening_access_again_keeps_the_same_link(): void
    {
        $token = $this->share();

        $this->trip->openPublicAccess();

        $this->assertSame($token, $this->trip->refresh()->public_token);
    }

    public function test_guest_sees_the_trip_by_token_without_login(): void
    {
        $day = $this->trip->days()->where('day_number', 1)->sole();
        $visit = $day->addPlace(Place::factory()->create(['name' => 'Кремль']));
        $visit->update(['start_time' => '10:00', 'note' => 'Личная заметка', 'needs_booking' => true, 'is_booked' => true]);
        Transport::factory()->for($this->trip)->create(['type' => 'train', 'departure_place' => 'Москва', 'arrival_place' => 'Казань', 'note' => 'Место 12, вагон 5']);
        Accommodation::factory()->for($this->trip)->create(['name' => 'Отель «Волга»', 'address' => 'ул. Баумана, 1', 'latitude' => 55.79, 'longitude' => 49.12]);

        $token = $this->share();

        $this->get("/t/{$token}")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Public')
                ->where('trip.destination', 'Казань')
                ->where('trip.placesCount', 1)
                ->has('days', 2)
                ->where('days.0.places.0.name', 'Кремль')
                ->where('days.0.places.0.startTime', '10:00')
                ->where('transports.0.departurePlace', 'Москва')
                ->where('accommodations.0.name', 'Отель «Волга»')
            );
    }

    public function test_public_page_shows_the_author_by_name_without_email(): void
    {
        $this->owner->update(['name' => 'Анна Петрова']);
        $token = $this->share();

        $response = $this->get("/t/{$token}")->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('author.name', 'Анна Петрова')
            ->where('author.initials', 'АП')
            ->where('author.avatarUrl', null)
            ->missing('author.email')
        );
        $this->assertStringNotContainsString('owner@example.com', json_encode($response->viewData('page')));
    }

    public function test_plan_tab_gets_the_road_between_places_but_not_from_lodging(): void
    {
        $day = $this->trip->days()->where('day_number', 1)->sole();
        $first = $day->addPlace(Place::factory()->create());
        $second = $day->addPlace(Place::factory()->create());
        // Дорога к первому месту идёт от жилья, ко второму — от первого места.
        $first->update(['travel_minutes' => 15, 'calculated_mode' => 'walk']);
        $second->update(['travel_minutes' => 20, 'calculated_mode' => 'transit', 'stay_minutes' => 90]);

        $this->get("/t/{$this->share()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.0.places.0.segment', null)
                ->where('days.0.places.1.segment', ['mode' => 'transit', 'minutes' => 20, 'approximate' => true])
                ->where('days.0.places.1.stayMinutes', 90)
            );
    }

    public function test_public_page_hides_personal_data(): void
    {
        $day = $this->trip->days()->where('day_number', 1)->sole();
        $day->addPlace(Place::factory()->create())->update(['note' => 'Личная заметка']);
        Transport::factory()->for($this->trip)->create(['note' => 'Место 12, вагон 5']);
        Accommodation::factory()->for($this->trip)->create(['booking_info' => 'Бронь № AB-123456', 'address' => 'ул. Баумана, 1', 'note' => 'Код домофона 42']);
        Accommodation::factory()->for($this->trip)->create(['type' => 'friends', 'name' => 'У тёти Розы']);

        $token = $this->share();

        $response = $this->get("/t/{$token}")->assertOk();

        // Проверяем все данные, которые уходят в браузер, а не только то, что видно на странице.
        $sentToBrowser = json_encode($response->viewData('page'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Казань', $sentToBrowser);

        foreach (['Отпуск с Машей', 'Секретное описание', 'owner@example.com', 'Личная заметка', 'Место 12, вагон 5',
            'AB-123456', 'ул. Баумана', 'Код домофона', 'тёти Розы'] as $secret) {
            $this->assertStringNotContainsString($secret, $sentToBrowser);
        }

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('trip.id')
            ->missing('days.0.places.0.note')
            ->missing('transports.0.isBooked')
            ->missing('accommodations.0.address')
            ->where('days.0.morningLodging', null)
        );
    }

    public function test_logged_in_stranger_sees_the_same_page(): void
    {
        $token = $this->share();

        $this->actingAs(User::factory()->create())
            ->get("/t/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Trips/Public'));
    }

    public function test_closed_access_shows_unavailable_page_with_404(): void
    {
        $token = $this->share();

        $this->actingAs($this->owner)->delete("/my-trips/{$this->trip->id}/share")->assertRedirect();
        auth()->logout();

        $this->trip->refresh();
        $this->assertNull($this->trip->public_token);
        $this->assertFalse($this->trip->is_public);

        $this->get("/t/{$token}")
            ->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Trips/PublicUnavailable'));

        // Снова открыли доступ — ссылка новая, старая так и не работает.
        $this->assertNotSame($token, $this->share());
        $this->get("/t/{$token}")->assertNotFound();
    }

    public function test_wrong_token_shows_unavailable_page(): void
    {
        $this->share();

        $this->get('/t/not-a-real-token')
            ->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Trips/PublicUnavailable'));
    }

    public function test_private_trip_is_not_available_by_id(): void
    {
        $this->get("/t/{$this->trip->id}")->assertNotFound();
    }

    public function test_stranger_cannot_open_or_close_access(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post("/my-trips/{$this->trip->id}/share")->assertForbidden();
        $this->assertNull($this->trip->refresh()->public_token);

        $token = $this->share();

        $this->actingAs($stranger)->delete("/my-trips/{$this->trip->id}/share")->assertForbidden();
        $this->assertSame($token, $this->trip->refresh()->public_token);
    }

    public function test_guest_cannot_open_access(): void
    {
        $this->post("/my-trips/{$this->trip->id}/share")->assertRedirect('/login');
        $this->assertNull($this->trip->refresh()->public_token);
    }

    public function test_settings_page_gives_the_public_link_to_the_owner(): void
    {
        $token = $this->share();

        $this->actingAs($this->owner)
            ->get("/my-trips/{$this->trip->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('trip.publicUrl', route('trips.public', $token))
                ->where('trip.isPublic', true)
            );
    }

    public function test_saving_settings_does_not_change_access(): void
    {
        $token = $this->share();

        $this->actingAs($this->owner)->put("/my-trips/{$this->trip->id}", [
            'title' => 'Новое название',
            'destination' => 'Казань',
            'start_date' => $this->trip->start_date->toDateString(),
            'end_date' => $this->trip->end_date->toDateString(),
            'is_private' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame($token, $this->trip->refresh()->public_token);
        $this->assertTrue($this->trip->is_public);
    }
}
