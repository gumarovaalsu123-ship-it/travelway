<?php

namespace Tests\Feature;

use App\Models\PackingItem;
use App\Models\PackingList;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TripPackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_groups_with_items_in_order(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();
        $documents = PackingList::factory()->for($trip)->create(['title' => 'Документы', 'position' => 1]);
        PackingList::factory()->for($trip)->create(['title' => 'Техника', 'position' => 2]);
        PackingItem::factory()->for($documents)->create(['name' => 'Билеты', 'position' => 2]);
        PackingItem::factory()->for($documents)->completed()->create(['name' => 'Паспорт', 'position' => 1]);

        $this->actingAs($user)
            ->get("/my-trips/{$trip->id}/packing")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Packing')
                ->where('trip.id', $trip->id)
                ->has('lists', 2)
                ->where('lists.0.title', 'Документы')
                ->where('lists.0.items.0.name', 'Паспорт')
                ->where('lists.0.items.0.isCompleted', true)
                ->where('lists.0.items.1.name', 'Билеты')
                ->has('lists.1.items', 0));
    }

    public function test_owner_can_add_rename_and_delete_group(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();

        $this->actingAs($user)
            ->post("/my-trips/{$trip->id}/packing-lists", ['title' => 'Одежда'])
            ->assertRedirect();

        $list = $trip->packingLists()->sole();
        $this->assertSame('Одежда', $list->title);

        $this->patch("/my-trips/{$trip->id}/packing-lists/{$list->id}", ['title' => 'Одежда и обувь'])->assertRedirect();
        $this->assertSame('Одежда и обувь', $list->fresh()->title);

        PackingItem::factory()->for($list)->create();

        $this->delete("/my-trips/{$trip->id}/packing-lists/{$list->id}")->assertRedirect();
        $this->assertDatabaseCount('packing_lists', 0);
        $this->assertDatabaseCount('packing_items', 0);
    }

    public function test_owner_can_add_item_to_end_of_group(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();
        $list = PackingList::factory()->for($trip)->create();
        PackingItem::factory()->for($list)->create(['position' => 1]);

        $this->actingAs($user)
            ->post("/my-trips/{$trip->id}/packing-lists/{$list->id}/items", ['name' => 'Зарядка', 'note' => 'USB-C'])
            ->assertRedirect();

        $this->assertDatabaseHas('packing_items', [
            'packing_list_id' => $list->id,
            'name' => 'Зарядка',
            'note' => 'USB-C',
            'is_completed' => false,
            'position' => 2,
        ]);
    }

    public function test_owner_can_tick_rename_and_delete_item(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();
        $item = PackingItem::factory()->for(PackingList::factory()->for($trip))->create(['name' => 'Зарядка']);

        $this->actingAs($user)->patch("/my-trips/{$trip->id}/packing-items/{$item->id}/toggle")->assertRedirect();
        $this->assertTrue($item->fresh()->is_completed);

        $this->patch("/my-trips/{$trip->id}/packing-items/{$item->id}/toggle");
        $this->assertFalse($item->fresh()->is_completed);

        $this->patch("/my-trips/{$trip->id}/packing-items/{$item->id}", ['name' => 'Зарядка для телефона'])->assertRedirect();
        $this->assertSame('Зарядка для телефона', $item->fresh()->name);

        $this->delete("/my-trips/{$trip->id}/packing-items/{$item->id}")->assertRedirect();
        $this->assertModelMissing($item);
    }

    public function test_standard_set_is_added_once(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();
        $itemsCount = count(array_merge(...array_values(PackingList::Defaults)));

        $this->actingAs($user)->post("/my-trips/{$trip->id}/packing-lists/defaults")->assertRedirect();
        $this->post("/my-trips/{$trip->id}/packing-lists/defaults");

        $this->assertSame(count(PackingList::Defaults), $trip->packingLists()->count());
        $this->assertSame($itemsCount, $trip->packingItems()->count());
    }

    public function test_validation_errors(): void
    {
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();
        $list = PackingList::factory()->for($trip)->create(['title' => 'Техника']);

        $this->actingAs($user)
            ->post("/my-trips/{$trip->id}/packing-lists/{$list->id}/items", ['name' => ''])
            ->assertSessionHasErrors(['name' => 'Заполните поле «название вещи».']);

        $this->post("/my-trips/{$trip->id}/packing-lists", ['title' => 'Техника'])
            ->assertSessionHasErrors(['title' => 'Группа с таким названием уже есть.']);

        $this->assertDatabaseCount('packing_items', 0);
        $this->assertDatabaseCount('packing_lists', 1);
    }

    public function test_other_user_cannot_see_or_change_packing_list(): void
    {
        $trip = Trip::factory()->create();
        $list = PackingList::factory()->for($trip)->create();
        $item = PackingItem::factory()->for($list)->create();

        $this->actingAs(User::factory()->create());

        $this->get("/my-trips/{$trip->id}/packing")->assertForbidden();
        $this->post("/my-trips/{$trip->id}/packing-lists", ['title' => 'Чужое'])->assertForbidden();
        $this->post("/my-trips/{$trip->id}/packing-lists/defaults")->assertForbidden();
        $this->post("/my-trips/{$trip->id}/packing-lists/{$list->id}/items", ['name' => 'Чужое'])->assertForbidden();
        $this->patch("/my-trips/{$trip->id}/packing-items/{$item->id}/toggle")->assertForbidden();
        $this->delete("/my-trips/{$trip->id}/packing-items/{$item->id}")->assertForbidden();
        $this->delete("/my-trips/{$trip->id}/packing-lists/{$list->id}")->assertForbidden();

        $this->assertFalse($item->fresh()->is_completed);
        $this->assertDatabaseCount('packing_lists', 1);
    }

    public function test_item_of_another_trip_cannot_be_changed_through_own_trip(): void
    {
        $user = User::factory()->create();
        $ownTrip = Trip::factory()->for($user)->create();
        $foreignItem = PackingItem::factory()->create();
        $foreignList = $foreignItem->packingList;

        $this->actingAs($user);

        $this->patch("/my-trips/{$ownTrip->id}/packing-items/{$foreignItem->id}/toggle")->assertNotFound();
        $this->delete("/my-trips/{$ownTrip->id}/packing-items/{$foreignItem->id}")->assertNotFound();
        $this->post("/my-trips/{$ownTrip->id}/packing-lists/{$foreignList->id}/items", ['name' => 'Чужое'])->assertNotFound();
        $this->delete("/my-trips/{$ownTrip->id}/packing-lists/{$foreignList->id}")->assertNotFound();

        $this->assertModelExists($foreignItem);
        $this->assertFalse($foreignItem->fresh()->is_completed);
    }
}
