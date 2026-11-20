<?php

namespace Tests\Feature;

use App\Models\Trip;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The demo routes are complete and are shown on the landing page right after php artisan db:seed.
 */
class DemoTripsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_demo_routes_are_full_public_and_on_the_landing_page(): void
    {
        $this->seed(DatabaseSeeder::class);

        $demoTrips = Trip::query()->featured()->withCount(['tripPlaces', 'transports', 'accommodations', 'packingLists'])->get();

        $this->assertCount(Trip::LandingExamplesLimit, $demoTrips);

        foreach ($demoTrips as $trip) {
            $this->assertTrue($trip->is_public);
            $this->assertGreaterThan(5, $trip->trip_places_count);
            $this->assertSame(2, $trip->transports_count);
            $this->assertSame(1, $trip->accommodations_count);
            $this->assertGreaterThan(0, $trip->packing_lists_count);
            // В каждом дне поездки есть хотя бы одно место.
            $this->assertSame(0, $trip->days()->doesntHave('tripPlaces')->count());
            $this->get("/t/{$trip->public_token}")->assertOk();
        }

        $this->assertEqualsCanonicalizing(
            [Trip::StatusPlanned, Trip::StatusCompleted, Trip::StatusPlanned],
            $demoTrips->pluck('status')->all(),
        );

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('examples', Trip::LandingExamplesLimit)
            ->where('examples.0.title', 'Казань за три дня'));
    }
}
