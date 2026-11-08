<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TripShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_trip_days_with_places(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create(['start_date' => '2026-06-15', 'end_date' => '2026-06-17']);
        $trip->syncDays();

        $firstDay = $trip->days()->first();
        $firstDay->addPlace(Place::factory()->create(['name' => 'Кремль']), '10:00');
        $firstDay->addPlace(Place::factory()->create(['name' => 'Кул-Шариф']));

        $this->actingAs($user)
            ->get("/my-trips/{$trip->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Show')
                ->where('trip.id', $trip->id)
                ->where('trip.daysCount', 3)
                ->where('trip.placesCount', 2)
                ->has('days', 3)
                ->where('days.0.date', '2026-06-15')
                ->where('days.0.places.0.name', 'Кремль')
                ->where('days.0.places.0.startTime', '10:00')
                ->where('days.0.places.1.name', 'Кул-Шариф')
                ->has('days.1.places', 0));
    }

    public function test_unfinished_tab_shows_coming_soon_page(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();

        $this->actingAs($user)
            ->get("/my-trips/{$trip->id}/transport")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/ComingSoon')
                ->where('tab', 'transport')
                ->where('trip.id', $trip->id));
    }

    public function test_other_user_cannot_open_trip(): void
    {
        $trip = Trip::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/my-trips/{$trip->id}")
            ->assertForbidden();

        $this->get("/my-trips/{$trip->id}/plan")->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $trip = Trip::factory()->create();

        $this->get("/my-trips/{$trip->id}")->assertRedirect('/login');
    }
}
