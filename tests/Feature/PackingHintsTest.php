<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\PackingItem;
use App\Models\PackingList;
use App\Models\Transport;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The cleaned "standard set" and the "Might come in handy" hints (PackingList::Hints).
 */
class PackingHintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_set_has_only_things_for_any_trip(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();

        $this->actingAs($user)->post("/my-trips/{$trip->id}/packing-lists/defaults")->assertRedirect();

        $names = $trip->packingItems()->pluck('name')->all();

        foreach (['Паспорт', 'Билеты', 'Пауэрбанк'] as $kept) {
            $this->assertContains($kept, $names);
        }

        foreach (['Солнцезащитный крем', 'Головной убор', 'Переходник для розетки'] as $removed) {
            $this->assertNotContains($removed, $names);
        }
    }

    public function test_long_ride_rule_needs_both_times_and_more_than_four_hours(): void
    {
        $trip = $this->draftTrip();
        Transport::factory()->for($trip)->create(['type' => 'bus', 'departure_at' => '2026-06-01 08:00', 'arrival_at' => '2026-06-01 12:00']);
        Transport::factory()->for($trip)->create(['type' => 'bus', 'departure_at' => '2026-06-05 08:00', 'arrival_at' => null]);

        $this->assertNotContains('long_ride', $this->hintRules($trip));

        Transport::factory()->for($trip)->create(['type' => 'plane', 'departure_at' => '2026-06-05 08:00', 'arrival_at' => '2026-06-05 12:30']);

        $this->assertContains('long_ride', $this->hintRules($trip));
    }

    public function test_train_rule(): void
    {
        $trip = $this->draftTrip();
        Transport::factory()->for($trip)->create(['type' => 'bus']);

        $this->assertNotContains('train', $this->hintRules($trip));

        Transport::factory()->for($trip)->create(['type' => 'train']);

        $this->assertContains('train', $this->hintRules($trip));
    }

    public function test_own_car_rule(): void
    {
        $trip = $this->draftTrip();
        Transport::factory()->for($trip)->create(['type' => 'plane']);

        $this->assertNotContains('own_car', $this->hintRules($trip));

        Transport::factory()->for($trip)->create(['type' => PackingList::OwnCarType]);

        $this->assertContains('own_car', $this->hintRules($trip));
    }

    public function test_hostel_rule(): void
    {
        $trip = $this->draftTrip();
        Accommodation::factory()->for($trip)->create(['name' => 'Отель «Волга»']);

        $this->assertNotContains('hostel', $this->hintRules($trip));

        Accommodation::factory()->for($trip)->create(['name' => 'Хостел «Друзья»']);

        $this->assertContains('hostel', $this->hintRules($trip));
    }

    public function test_long_trip_rule_works_after_seven_days(): void
    {
        $this->assertNotContains('long_trip', $this->hintRules($this->draftTrip(7)));
        $this->assertContains('long_trip', $this->hintRules($this->draftTrip(8)));
    }

    public function test_season_rules_follow_trip_dates(): void
    {
        $this->assertSame(['winter'], $this->hintRules($this->datedTrip('2027-01-10', '2027-01-12')));
        $this->assertSame(['spring_autumn'], $this->hintRules($this->datedTrip('2026-04-10', '2026-04-12')));
        $this->assertSame(['spring_autumn'], $this->hintRules($this->datedTrip('2026-10-10', '2026-10-12')));
        $this->assertSame(['summer'], $this->hintRules($this->datedTrip('2026-07-10', '2026-07-12')));
    }

    public function test_trip_on_season_border_gets_both_season_rules(): void
    {
        $this->assertSame(['spring_autumn', 'summer'], $this->hintRules($this->datedTrip('2026-08-30', '2026-09-02')));
    }

    public function test_draft_without_dates_gets_no_season_rules(): void
    {
        $this->assertSame([], $this->hintRules($this->draftTrip(3)));
    }

    public function test_things_already_in_the_list_are_not_suggested_ignoring_case(): void
    {
        $trip = $this->datedTrip('2026-07-10', '2026-07-12');
        Transport::factory()->for($trip)->create(['type' => 'bus', 'departure_at' => '2026-07-10 08:00', 'arrival_at' => '2026-07-10 14:00']);
        $list = PackingList::factory()->for($trip)->create();
        PackingItem::factory()->for($list)->create(['name' => 'подушка для шеи']);
        PackingItem::factory()->for($list)->create(['name' => 'Головной убор ']);

        $hints = collect(PackingList::hintsFor($trip->fresh()))->keyBy('rule');

        // У «долгой дороги» вещь всего одна, и она уже есть — подсказка не показывается совсем.
        $this->assertFalse($hints->has('long_ride'));
        $this->assertSame(['Солнцезащитный крем', 'Бутылка для воды'], $hints['summer']['items']);
    }

    public function test_page_shows_hints(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create(['start_date' => '2027-01-10', 'end_date' => '2027-01-12']);
        Transport::factory()->for($trip)->create(['type' => 'train', 'departure_at' => null, 'arrival_at' => null]);

        $this->actingAs($user)
            ->get("/my-trips/{$trip->id}/packing")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Packing')
                ->has('hints', 2)
                ->where('hints.0.rule', 'train')
                ->where('hints.0.items', ['Тапочки', 'Кружка'])
                ->where('hints.1.rule', 'winter'));
    }

    public function test_owner_adds_chosen_hint_things_to_hint_group(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();
        PackingItem::factory()->for(PackingList::factory()->for($trip))->create(['name' => 'Кружка']);

        $this->actingAs($user)
            ->post("/my-trips/{$trip->id}/packing-lists/hints", ['rule' => 'train', 'items' => ['Тапочки', 'Кружка']])
            ->assertRedirect();

        $group = $trip->packingLists()->where('title', 'В дорогу')->sole();
        $this->assertSame(['Тапочки'], $group->items()->pluck('name')->all());
        $this->assertSame(1, $trip->packingItems()->where('name', 'Кружка')->count());
    }

    public function test_only_things_of_the_chosen_hint_can_be_added(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();

        $this->actingAs($user)
            ->post("/my-trips/{$trip->id}/packing-lists/hints", ['rule' => 'train', 'items' => ['Шапка']])
            ->assertSessionHasErrors('items.0');

        $this->post("/my-trips/{$trip->id}/packing-lists/hints", ['rule' => 'unknown', 'items' => ['Тапочки']])
            ->assertSessionHasErrors('rule');

        $this->post("/my-trips/{$trip->id}/packing-lists/hints", ['rule' => 'train', 'items' => []])
            ->assertSessionHasErrors(['items' => 'Отметьте хотя бы одну вещь.']);

        $this->assertDatabaseCount('packing_items', 0);
    }

    public function test_other_user_cannot_add_hint_things(): void
    {
        $trip = Trip::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post("/my-trips/{$trip->id}/packing-lists/hints", ['rule' => 'train', 'items' => ['Тапочки']])
            ->assertForbidden();

        $this->assertDatabaseCount('packing_items', 0);
    }

    /**
     * A trip without dates, so that the season rules stay silent.
     */
    protected function draftTrip(int $daysCount = 3): Trip
    {
        return Trip::factory()->create(['start_date' => null, 'end_date' => null, 'days_count' => $daysCount]);
    }

    protected function datedTrip(string $startDate, string $endDate): Trip
    {
        return Trip::factory()->create(['start_date' => $startDate, 'end_date' => $endDate]);
    }

    /**
     * Rules that work for the trip, in the order of PackingList::Hints.
     *
     * @return array<int, string>
     */
    protected function hintRules(Trip $trip): array
    {
        return array_column(PackingList::hintsFor($trip->fresh()), 'rule');
    }
}
