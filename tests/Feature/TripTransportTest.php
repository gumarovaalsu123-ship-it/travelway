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
        $this->trip = Trip::factory()->for($this->user)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'type' => 'plane',
            'departure_place' => 'Москва, Шереметьево',
            'arrival_place' => 'Казань',
            'departure_at' => '2026-06-15T13:15',
            'arrival_at' => '2026-06-15T15:00',
            'booking_info' => 'SU 1234',
            'note' => 'Багаж 23 кг',
            ...$overrides,
        ];
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
            'departure_place' => 'Москва, Шереметьево',
            'booking_info' => 'SU 1234',
        ]);
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
            ->put("/my-trips/{$this->trip->id}/transport/{$transport->id}", $this->payload(['type' => 'train', 'arrival_place' => 'Сочи']))
            ->assertRedirect("/my-trips/{$this->trip->id}/transport");

        $transport->refresh();
        $this->assertSame('train', $transport->type);
        $this->assertSame('Сочи', $transport->arrival_place);
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
}
