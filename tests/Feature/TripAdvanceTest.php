<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Transport;
use App\Models\Trip;
use App\Models\TripPlace;
use App\Models\TripTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The "Заранее" tab: automatic to-dos, own to-dos and the booking marks of places in the plan.
 */
class TripAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-06-01 12:00');

        $this->user = User::factory()->create();
        $this->trip = Trip::factory()->for($this->user)->create(['start_date' => '2026-06-15', 'end_date' => '2026-06-17']);
    }

    private function addVisit(): TripPlace
    {
        return $this->trip->days()->first()->addPlace(Place::factory()->create([
            'name' => 'Кремль',
            // Категория задана явно: от неё зависит текст дела («Купить билет» у музея).
            'category_id' => PlaceCategory::firstWhere('slug', 'museum')->id,
        ]));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get("/my-trips/{$this->trip->id}/advance")->assertRedirect('/login');
    }

    public function test_page_shows_automatic_and_own_tasks_with_days_left(): void
    {
        Transport::factory()->for($this->trip)->create([
            'type' => 'train', 'departure_place' => 'Москва', 'arrival_place' => 'Казань',
            'departure_at' => '2026-06-15 08:00', 'is_booked' => false,
        ]);
        Transport::factory()->for($this->trip)->create(['type' => 'own_car', 'is_booked' => false]);
        Accommodation::factory()->for($this->trip)->create(['type' => 'friends', 'is_booked' => false]);
        $this->addVisit()->update(['needs_booking' => true]);
        TripTask::factory()->for($this->trip)->create(['title' => 'Оформить страховку', 'due_date' => '2026-05-30']);
        TripTask::factory()->create(['title' => 'Чужое дело']);

        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/advance")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Advance')
                ->where('trip.id', $this->trip->id)
                // Своё авто и жильё у знакомых в дела не попадают.
                ->has('tasks', 3)
                ->where('tasks.0.title', 'Оформить страховку')
                ->where('tasks.0.kind', 'task')
                ->where('tasks.0.daysLeft', -2)
                // Место дня 1 (срок — 15 июня) раньше поезда 15 июня в 08:00.
                ->where('tasks.1.title', 'Купить билет: Кремль (день 1)')
                ->where('tasks.1.kind', 'place')
                ->where('tasks.1.tab', 'plan')
                ->where('tasks.2.title', 'Купить билет: Поезд Москва → Казань')
                ->where('tasks.2.dueDate', '2026-06-15')
                ->where('tasks.2.daysLeft', 14)
                ->where('tasks.2.tab', 'transport'));
    }

    public function test_owner_can_add_a_task(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/tasks", ['title' => 'Оформить страховку', 'due_date' => '2026-06-10'])
            ->assertRedirect();

        $task = $this->trip->tasks()->sole();
        $this->assertSame('Оформить страховку', $task->title);
        $this->assertSame('2026-06-10', $task->due_date->toDateString());
        $this->assertFalse($task->is_done);
    }

    public function test_task_needs_a_title(): void
    {
        $this->actingAs($this->user)
            ->post("/my-trips/{$this->trip->id}/tasks", ['title' => '', 'due_date' => 'не дата'])
            ->assertSessionHasErrors(['title', 'due_date']);

        $this->assertSame(0, $this->trip->tasks()->count());
    }

    public function test_owner_can_edit_tick_and_delete_a_task(): void
    {
        $task = TripTask::factory()->for($this->trip)->create(['title' => 'Страховка', 'due_date' => null]);
        $url = "/my-trips/{$this->trip->id}/tasks/{$task->id}";

        $this->actingAs($this->user)->put($url, ['title' => 'Оформить страховку', 'due_date' => '2026-06-12'])->assertRedirect();
        $this->assertSame('Оформить страховку', $task->fresh()->title);
        $this->assertSame('2026-06-12', $task->fresh()->due_date->toDateString());

        $this->actingAs($this->user)->patch("{$url}/toggle")->assertRedirect();
        $this->assertTrue($task->fresh()->is_done);

        $this->actingAs($this->user)->patch("{$url}/toggle");
        $this->assertFalse($task->fresh()->is_done);

        $this->actingAs($this->user)->delete($url)->assertRedirect();
        $this->assertModelMissing($task);
    }

    public function test_ticking_an_automatic_task_marks_the_record_itself(): void
    {
        $ride = Transport::factory()->for($this->trip)->create(['type' => 'plane', 'is_booked' => false]);
        $stay = Accommodation::factory()->for($this->trip)->create(['type' => 'hotel', 'is_booked' => false]);

        $this->actingAs($this->user)->patch("/my-trips/{$this->trip->id}/transport/{$ride->id}/booked")->assertRedirect();
        $this->actingAs($this->user)->patch("/my-trips/{$this->trip->id}/accommodation/{$stay->id}/booked")->assertRedirect();

        $this->assertTrue($ride->fresh()->is_booked);
        $this->assertTrue($stay->fresh()->is_booked);
    }

    public function test_own_car_and_staying_with_friends_cannot_be_marked(): void
    {
        $car = Transport::factory()->for($this->trip)->create(['type' => 'own_car', 'is_booked' => false]);
        $friends = Accommodation::factory()->for($this->trip)->create(['type' => 'friends', 'is_booked' => false]);

        $this->actingAs($this->user)->patch("/my-trips/{$this->trip->id}/transport/{$car->id}/booked")->assertStatus(422);
        $this->actingAs($this->user)->patch("/my-trips/{$this->trip->id}/accommodation/{$friends->id}/booked")->assertStatus(422);
    }

    public function test_place_booking_marks(): void
    {
        $visit = $this->addVisit();
        $url = "/my-trips/{$this->trip->id}/trip-places/{$visit->id}/booking";

        $this->actingAs($this->user)->patch($url, ['needs_booking' => true])->assertRedirect();
        $this->assertTrue($visit->fresh()->needs_booking);
        $this->assertFalse($visit->fresh()->is_booked);

        $this->actingAs($this->user)->patch($url, ['is_booked' => true]);
        $this->assertTrue($visit->fresh()->is_booked);

        // Билет больше не нужен — отметка «куплено» тоже снимается.
        $this->actingAs($this->user)->patch($url, ['needs_booking' => false]);
        $this->assertFalse($visit->fresh()->needs_booking);
        $this->assertFalse($visit->fresh()->is_booked);
    }

    public function test_plan_page_shows_booking_marks(): void
    {
        $this->addVisit()->update(['needs_booking' => true]);

        $this->actingAs($this->user)
            ->get("/my-trips/{$this->trip->id}/plan")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.places.0.needsBooking', true)
                ->where('selectedDay.places.0.isBooked', false)
                ->has('selectedDay.places.0.categorySlug'));
    }

    public function test_someone_elses_trip_is_forbidden(): void
    {
        $stranger = User::factory()->create();
        $task = TripTask::factory()->for($this->trip)->create();
        $visit = $this->addVisit();
        $ride = Transport::factory()->for($this->trip)->create(['type' => 'plane', 'is_booked' => false]);
        $base = "/my-trips/{$this->trip->id}";

        $this->actingAs($stranger)->get("{$base}/advance")->assertForbidden();
        $this->actingAs($stranger)->post("{$base}/tasks", ['title' => 'Взлом'])->assertForbidden();
        $this->actingAs($stranger)->put("{$base}/tasks/{$task->id}", ['title' => 'Взлом'])->assertForbidden();
        $this->actingAs($stranger)->patch("{$base}/tasks/{$task->id}/toggle")->assertForbidden();
        $this->actingAs($stranger)->delete("{$base}/tasks/{$task->id}")->assertForbidden();
        $this->actingAs($stranger)->patch("{$base}/transport/{$ride->id}/booked")->assertForbidden();
        $this->actingAs($stranger)->patch("{$base}/trip-places/{$visit->id}/booking", ['needs_booking' => true])->assertForbidden();

        $this->assertModelExists($task);
        $this->assertFalse($task->fresh()->is_done);
        $this->assertFalse($ride->fresh()->is_booked);
        $this->assertFalse($visit->fresh()->needs_booking);
    }

    public function test_task_of_another_trip_is_not_found_through_your_trip(): void
    {
        $otherTask = TripTask::factory()->create();

        $this->actingAs($this->user)
            ->patch("/my-trips/{$this->trip->id}/tasks/{$otherTask->id}/toggle")
            ->assertNotFound();
    }
}
