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

    public function test_preparation_tab_shows_steps_and_readiness(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create(['start_date' => '2026-06-15', 'end_date' => '2026-06-17']);
        $trip->days()->first()->addPlace(Place::factory()->create(['name' => 'Кремль']));

        $this->actingAs($user)
            ->get("/my-trips/{$trip->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Show')
                ->where('trip.id', $trip->id)
                ->where('trip.placesCount', 1)
                // Готов только шаг «Заранее»: дел нет.
                ->where('readiness.percent', 20)
                ->has('readiness.steps', 5)
                ->where('readiness.steps.0.key', 'transport')
                ->where('readiness.steps.0.ready', false)
                ->where('readiness.steps.0.tab', 'transport')
                ->where('readiness.steps.1.hints.0.text', 'Ночи 1 → 3 (15–17 июня) без жилья')
                ->where('readiness.steps.3.key', 'advance')
                ->where('readiness.steps.3.ready', true)
                ->where('readiness.steps.4.summary', 'Дней с местами: 1 из 3')
                ->where('readiness.steps.4.hints.0.tab', 'plan'));
    }

    public function test_unfinished_tab_shows_coming_soon_page(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();

        $this->actingAs($user)
            ->get("/my-trips/{$trip->id}/map")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/ComingSoon')
                ->where('tab', 'map')
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
