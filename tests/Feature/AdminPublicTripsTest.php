<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * "Публичные маршруты" in the admin panel: moderation of public links and "Примеры маршрутов" on the landing page.
 */
class AdminPublicTripsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role_id' => Role::Admin]);
    }

    private function publicTrip(array $attributes = []): Trip
    {
        $trip = Trip::factory()->create($attributes);
        $trip->openPublicAccess();

        return $trip;
    }

    public function test_admin_sees_only_public_trips_with_featured_first(): void
    {
        $this->publicTrip(['title' => 'Обычная']);
        $featured = $this->publicTrip(['title' => 'На главной']);
        $featured->forceFill(['featured_at' => now()])->save();
        Trip::factory()->create(['title' => 'Закрытая']);

        $this->actingAs($this->admin)
            ->get('/admin/trips')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/PublicTrips')
                ->has('trips.data', 2)
                ->where('trips.data.0.title', 'На главной')
                ->where('trips.data.0.isFeatured', true)
                ->where('featuredCount', 1)
                ->where('featuredLimit', Trip::LandingExamplesLimit));
    }

    public function test_search_by_city_or_author(): void
    {
        $this->publicTrip(['destination' => 'Казань']);
        $this->publicTrip(['destination' => 'Псков', 'user_id' => User::factory()->create(['name' => 'Мария Иванова'])]);

        $this->actingAs($this->admin)
            ->get('/admin/trips?search=Иванова')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('trips.data', 1)
                ->where('trips.data.0.destination', 'Псков'));
    }

    public function test_admin_puts_trip_on_landing_and_removes_it(): void
    {
        $trip = $this->publicTrip(['title' => 'Казань за выходные']);

        $this->actingAs($this->admin)
            ->patch("/admin/trips/{$trip->id}/feature")
            ->assertInertiaFlash('toast', '«Казань за выходные» теперь в примерах на главной.');

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('examples', 1)
            ->where('examples.0.title', 'Казань за выходные')
            ->where('examples.0.publicUrl', route('trips.public', $trip->public_token)));

        $this->actingAs($this->admin)->patch("/admin/trips/{$trip->id}/unfeature");

        $this->assertNull($trip->refresh()->featured_at);
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->has('examples', 0));
    }

    public function test_landing_has_at_most_three_examples(): void
    {
        foreach (range(1, Trip::LandingExamplesLimit) as $number) {
            $this->publicTrip()->forceFill(['featured_at' => now()->subMinutes($number)])->save();
        }
        $extra = $this->publicTrip();

        $this->actingAs($this->admin)
            ->patch("/admin/trips/{$extra->id}/feature")
            ->assertSessionHasErrors('trip');

        $this->assertNull($extra->refresh()->featured_at);
    }

    public function test_private_trip_cannot_be_put_on_landing(): void
    {
        $trip = Trip::factory()->create();

        $this->actingAs($this->admin)->patch("/admin/trips/{$trip->id}/feature")->assertNotFound();

        $this->assertNull($trip->refresh()->featured_at);
    }

    public function test_closing_access_removes_the_link_and_the_trip_from_landing(): void
    {
        $trip = $this->publicTrip();
        $trip->forceFill(['featured_at' => now()])->save();
        $token = $trip->public_token;

        $this->actingAs($this->admin)
            ->delete("/admin/trips/{$trip->id}/share")
            ->assertRedirect();

        $trip->refresh();
        $this->assertFalse($trip->is_public);
        $this->assertNull($trip->featured_at);
        $this->get("/t/{$token}")->assertNotFound();
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->has('examples', 0));
    }

    public function test_regular_user_cannot_moderate(): void
    {
        $trip = $this->publicTrip();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/trips')->assertForbidden();
        $this->actingAs($user)->patch("/admin/trips/{$trip->id}/feature")->assertForbidden();
        $this->actingAs($user)->delete("/admin/trips/{$trip->id}/share")->assertForbidden();

        $this->assertTrue($trip->refresh()->is_public);
    }
}
