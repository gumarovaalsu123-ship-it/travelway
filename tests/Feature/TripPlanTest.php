<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TripPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->trip = Trip::factory()->for($this->user)->create([
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth()->addDays(2),
        ]);
    }

    private function day(int $number): TripDay
    {
        return $this->trip->days()->where('day_number', $number)->sole();
    }

    private function planUrl(string $path = ''): string
    {
        return "/my-trips/{$this->trip->id}{$path}";
    }

    // --- Шаг 1. Каркас вкладки ---

    public function test_owner_sees_selected_day_with_places_in_order(): void
    {
        $second = $this->day(2);
        $second->update(['title' => 'Знакомство с городом']);
        $later = $second->addPlace(Place::factory()->create(['name' => 'Музей']));
        $second->addPlace(Place::factory()->create(['name' => 'Кафе']));
        $later->update(['start_time' => '10:00', 'end_time' => '11:30', 'note' => 'Взять билеты']);

        $this->actingAs($this->user)
            ->get($this->planUrl('/plan?day=2'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Plan')
                ->where('trip.id', $this->trip->id)
                ->has('days', 3)
                ->where('days.1.placesCount', 2)
                ->where('days.0.placesCount', 0)
                ->where('selectedDay.number', 2)
                ->where('selectedDay.title', 'Знакомство с городом')
                ->where('selectedDay.places.0.name', 'Музей')
                ->where('selectedDay.places.0.startTime', '10:00')
                ->where('selectedDay.places.0.endTime', '11:30')
                ->where('selectedDay.places.0.note', 'Взять билеты')
                ->where('selectedDay.places.1.name', 'Кафе')
                ->where('selectedDay.places.1.startTime', null));
    }

    public function test_stranger_cannot_open_the_plan(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->planUrl('/plan'))
            ->assertForbidden();
    }

    public function test_unknown_day_opens_day_one(): void
    {
        foreach (['?day=99', '?day=0', '?day=abc', ''] as $query) {
            $this->actingAs($this->user)
                ->get($this->planUrl("/plan{$query}"))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('selectedDay.number', 1));
        }
    }

    public function test_current_trip_opens_todays_day(): void
    {
        $trip = Trip::factory()->for($this->user)->create([
            'start_date' => today()->subDay(),
            'end_date' => today()->addDays(2),
        ]);

        $this->actingAs($this->user)
            ->get("/my-trips/{$trip->id}/plan")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selectedDay.number', 2));
    }

    public function test_draft_trip_shows_days_without_dates(): void
    {
        $draft = Trip::factory()->for($this->user)->create(['start_date' => null, 'end_date' => null, 'days_count' => 4]);

        $this->actingAs($this->user)
            ->get("/my-trips/{$draft->id}/plan?day=3")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('days', 4)
                ->where('days.0.date', null)
                ->where('selectedDay.number', 3)
                ->where('selectedDay.date', null));
    }

    public function test_plan_lists_only_own_favorites(): void
    {
        $favorite = Place::factory()->create(['name' => 'Мой любимый парк']);
        $this->user->favoritePlaces()->attach($favorite);
        User::factory()->create()->favoritePlaces()->attach(Place::factory()->create());

        $this->actingAs($this->user)
            ->get($this->planUrl('/plan'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('favorites', 1)
                ->where('favorites.0.id', $favorite->id)
                ->where('favorites.0.name', 'Мой любимый парк'));
    }

    // --- Шаг 2. Название дня ---

    public function test_owner_can_rename_day_and_clear_the_title(): void
    {
        $day = $this->day(1);

        $this->actingAs($this->user)
            ->patch($this->planUrl("/days/{$day->id}"), ['title' => 'Прогулка по центру'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->assertSame('Прогулка по центру', $day->fresh()->title);

        // Пустая строка сохраняется как null — тогда на странице просто «День 1».
        $this->patch($this->planUrl("/days/{$day->id}"), ['title' => ''])->assertSessionHasNoErrors();
        $this->assertNull($day->fresh()->title);
    }

    public function test_day_title_is_limited_to_100_characters(): void
    {
        $day = $this->day(1);

        $this->actingAs($this->user)
            ->patch($this->planUrl("/days/{$day->id}"), ['title' => str_repeat('а', 101)])
            ->assertSessionHasErrors('title');

        $this->assertNull($day->fresh()->title);
    }

    public function test_stranger_cannot_rename_day_and_day_of_another_trip_is_not_found(): void
    {
        $day = $this->day(1);

        $this->actingAs(User::factory()->create())
            ->patch($this->planUrl("/days/{$day->id}"), ['title' => 'Чужое'])
            ->assertForbidden();

        $foreignDay = Trip::factory()->create()->days()->first();

        $this->actingAs($this->user)
            ->patch($this->planUrl("/days/{$foreignDay->id}"), ['title' => 'Чужое'])
            ->assertNotFound();

        $this->assertNull($day->fresh()->title);
        $this->assertNull($foreignDay->fresh()->title);
    }

    // --- Шаг 3. Время и заметка ---

    public function test_owner_can_set_time_and_note_and_clear_them(): void
    {
        $visit = $this->day(1)->addPlace(Place::factory()->create());

        $this->actingAs($this->user)
            ->patch($this->planUrl("/trip-places/{$visit->id}"), ['start_time' => '10:00', 'end_time' => '11:30', 'note' => 'Купить билеты заранее'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $visit->refresh();
        // В MySQL время хранится как «10:00:00», поэтому сравниваем первые пять символов.
        $this->assertSame('10:00', substr($visit->start_time, 0, 5));
        $this->assertSame('11:30', substr($visit->end_time, 0, 5));
        $this->assertSame('Купить билеты заранее', $visit->note);

        $this->patch($this->planUrl("/trip-places/{$visit->id}"), ['start_time' => '', 'end_time' => '', 'note' => ''])
            ->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertNull($visit->start_time);
        $this->assertNull($visit->end_time);
        $this->assertNull($visit->note);
    }

    public function test_time_validation(): void
    {
        $visit = $this->day(1)->addPlace(Place::factory()->create());
        $url = $this->planUrl("/trip-places/{$visit->id}");

        $this->actingAs($this->user)
            ->patch($url, ['start_time' => '12:00', 'end_time' => '11:00'])
            ->assertSessionHasErrors(['end_time' => 'Окончание должно быть позже начала.']);

        $this->patch($url, ['start_time' => '', 'end_time' => '11:00'])
            ->assertSessionHasErrors('start_time');

        $this->patch($url, ['start_time' => '25:00'])
            ->assertSessionHasErrors('start_time');

        $this->patch($url, ['note' => str_repeat('а', 501)])
            ->assertSessionHasErrors('note');

        $this->assertNull($visit->fresh()->start_time);
    }

    public function test_stranger_cannot_change_a_place_and_place_of_another_trip_is_not_found(): void
    {
        $visit = $this->day(1)->addPlace(Place::factory()->create());

        $this->actingAs(User::factory()->create())
            ->patch($this->planUrl("/trip-places/{$visit->id}"), ['note' => 'Чужое'])
            ->assertForbidden();

        $foreignVisit = Trip::factory()->create()->days()->first()->addPlace(Place::factory()->create());

        $this->actingAs($this->user)
            ->patch($this->planUrl("/trip-places/{$foreignVisit->id}"), ['note' => 'Чужое'])
            ->assertNotFound();

        $this->assertNull($visit->fresh()->note);
        $this->assertNull($foreignVisit->fresh()->note);
    }

    // --- Шаг 4. Кнопки ↑↓ ---

    /**
     * Names of the places of the day in their visit order.
     *
     * @return array<int, string>
     */
    private function placeNames(TripDay $day): array
    {
        return $day->tripPlaces()->with('place')->get()->map(fn (TripPlace $visit) => $visit->place->name)->all();
    }

    public function test_place_can_be_moved_up_and_down(): void
    {
        $day = $this->day(1);
        $day->addPlace(Place::factory()->create(['name' => 'А']));
        $day->addPlace(Place::factory()->create(['name' => 'Б']));
        $third = $day->addPlace(Place::factory()->create(['name' => 'В']));

        $this->actingAs($this->user)
            ->patch($this->planUrl("/trip-places/{$third->id}/move"), ['direction' => 'up'])
            ->assertRedirect();
        $this->assertSame(['А', 'В', 'Б'], $this->placeNames($day));

        $this->patch($this->planUrl("/trip-places/{$third->id}/move"), ['direction' => 'down']);
        $this->assertSame(['А', 'Б', 'В'], $this->placeNames($day));
    }

    public function test_first_place_cannot_go_up_and_last_cannot_go_down(): void
    {
        $day = $this->day(1);
        $first = $day->addPlace(Place::factory()->create(['name' => 'А']));
        $last = $day->addPlace(Place::factory()->create(['name' => 'Б']));

        $this->actingAs($this->user)
            ->patch($this->planUrl("/trip-places/{$first->id}/move"), ['direction' => 'up'])
            ->assertRedirect();
        $this->patch($this->planUrl("/trip-places/{$last->id}/move"), ['direction' => 'down']);

        $this->assertSame(['А', 'Б'], $this->placeNames($day));
    }

    public function test_moving_works_with_gaps_and_ignores_other_days(): void
    {
        $day = $this->day(1);
        $otherDay = $this->day(2);
        $first = $day->addPlace(Place::factory()->create(['name' => 'А']));
        $removed = $day->addPlace(Place::factory()->create(['name' => 'Удалённое']));
        $second = $day->addPlace(Place::factory()->create(['name' => 'Б']));
        $removed->delete(); // В нумерации дыра: 1, 3.
        $foreign = $otherDay->addPlace(Place::factory()->create(['name' => 'Другой день']));

        $this->actingAs($this->user)
            ->patch($this->planUrl("/trip-places/{$second->id}/move"), ['direction' => 'up']);

        $this->assertSame(['Б', 'А'], $this->placeNames($day));
        $this->assertSame(1, $foreign->fresh()->visit_order);
        $this->assertSame($otherDay->id, $foreign->fresh()->trip_day_id);
        $this->assertSame(3, $first->fresh()->visit_order);
    }

    public function test_move_direction_is_validated(): void
    {
        $visit = $this->day(1)->addPlace(Place::factory()->create());

        $this->actingAs($this->user)
            ->patch($this->planUrl("/trip-places/{$visit->id}/move"), ['direction' => 'left'])
            ->assertSessionHasErrors('direction');
    }

    // --- Шаг 5. Перенос в другой день ---

    public function test_place_moves_to_the_end_of_another_day_with_its_time_and_note(): void
    {
        $visit = $this->day(1)->addPlace(Place::factory()->create(['name' => 'Переезжает']));
        $visit->update(['start_time' => '15:00', 'note' => 'Заметка']);
        $target = $this->day(3);
        $target->addPlace(Place::factory()->create(['name' => 'Уже там']));

        $this->actingAs($this->user)
            ->patch($this->planUrl("/trip-places/{$visit->id}/day"), ['trip_day_id' => $target->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $visit->refresh();
        $this->assertSame($target->id, $visit->trip_day_id);
        $this->assertSame(2, $visit->visit_order);
        $this->assertSame('15:00', substr($visit->start_time, 0, 5));
        $this->assertSame('Заметка', $visit->note);
        $this->assertSame(['Уже там', 'Переезжает'], $this->placeNames($target));
    }

    public function test_place_cannot_move_to_a_day_of_another_trip_or_to_the_same_day(): void
    {
        $day = $this->day(1);
        $visit = $day->addPlace(Place::factory()->create());
        $foreignDay = Trip::factory()->create()->days()->first();

        $this->actingAs($this->user)
            ->patch($this->planUrl("/trip-places/{$visit->id}/day"), ['trip_day_id' => $foreignDay->id])
            ->assertSessionHasErrors('trip_day_id');

        $this->patch($this->planUrl("/trip-places/{$visit->id}/day"), ['trip_day_id' => $day->id])
            ->assertSessionHasErrors('trip_day_id');

        $this->patch($this->planUrl("/trip-places/{$visit->id}/day"), [])
            ->assertSessionHasErrors('trip_day_id');

        $this->assertSame($day->id, $visit->fresh()->trip_day_id);
    }

    public function test_stranger_cannot_move_a_place(): void
    {
        $visit = $this->day(1)->addPlace(Place::factory()->create());

        $this->actingAs(User::factory()->create())
            ->patch($this->planUrl("/trip-places/{$visit->id}/day"), ['trip_day_id' => $this->day(2)->id])
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->patch($this->planUrl("/trip-places/{$visit->id}/move"), ['direction' => 'down'])
            ->assertForbidden();
    }

    // --- Шаг 7. Добавление из избранного в выбранный день ---

    public function test_favorite_place_is_added_to_the_end_of_the_chosen_day(): void
    {
        $day = $this->day(2);
        $day->addPlace(Place::factory()->create(['name' => 'Первое']));
        $favorite = Place::factory()->create(['name' => 'Из избранного']);
        $this->user->favoritePlaces()->attach($favorite);

        $this->actingAs($this->user)
            ->post("/places/{$favorite->id}/trip-places", ['trip_day_id' => $day->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Первое', 'Из избранного'], $this->placeNames($day));
    }

    // --- Шаг 9. Упорядочить по времени ---

    public function test_places_can_be_sorted_by_time_with_untimed_places_last(): void
    {
        $day = $this->day(1);
        $day->addPlace(Place::factory()->create(['name' => 'Без времени 1']));
        $day->addPlace(Place::factory()->create(['name' => 'Обед']), '13:00');
        $day->addPlace(Place::factory()->create(['name' => 'Без времени 2']));
        $day->addPlace(Place::factory()->create(['name' => 'Завтрак']), '09:00');

        $this->actingAs($this->user)
            ->post($this->planUrl("/days/{$day->id}/sort-by-time"))
            ->assertRedirect();

        $this->assertSame(['Завтрак', 'Обед', 'Без времени 1', 'Без времени 2'], $this->placeNames($day));
        $this->assertSame([1, 2, 3, 4], $day->tripPlaces()->pluck('visit_order')->all());
    }

    public function test_stranger_cannot_sort_the_day(): void
    {
        $day = $this->day(1);

        $this->actingAs(User::factory()->create())
            ->post($this->planUrl("/days/{$day->id}/sort-by-time"))
            ->assertForbidden();
    }
}
