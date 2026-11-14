<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\PackingItem;
use App\Models\PackingList;
use App\Models\Place;
use App\Models\Transport;
use App\Models\Trip;
use App\Services\TripReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rules of the trip check on the "Preparation" tab (App\Services\TripReadiness): one test per rule.
 */
class TripReadinessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A 3-day trip (2 nights): 15, 16 and 17 June.
     */
    protected function makeTrip(array $attributes = []): Trip
    {
        return Trip::factory()->create(['start_date' => '2026-06-15', 'end_date' => '2026-06-17', ...$attributes]);
    }

    protected function readiness(Trip $trip): TripReadiness
    {
        // fresh() — чтобы связи поездки загрузились заново, уже с созданными в тесте записями.
        return new TripReadiness($trip->fresh());
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<int, string>
     */
    protected function hintTexts(array $step, string $kind = 'hints'): array
    {
        return array_column($step[$kind], 'text');
    }

    protected function addRide(Trip $trip, string $direction, array $attributes = []): Transport
    {
        return Transport::factory()->for($trip)->create([
            'type' => 'train',
            'direction' => $direction,
            'departure_place' => 'Москва',
            'arrival_place' => 'Казань',
            'departure_at' => $direction === 'to' ? '2026-06-15 08:00' : '2026-06-17 18:00',
            'arrival_at' => $direction === 'to' ? '2026-06-15 12:00' : '2026-06-17 22:00',
            'is_booked' => true,
            ...$attributes,
        ]);
    }

    protected function addStay(Trip $trip, string $checkIn, string $checkOut, array $attributes = []): Accommodation
    {
        return Accommodation::factory()->for($trip)->create([
            'name' => 'Отель «Волга»',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            ...$attributes,
        ]);
    }

    protected function addPackingItems(Trip $trip, int $packed, int $notPacked): void
    {
        $list = PackingList::factory()->for($trip)->create();

        PackingItem::factory()->count($packed)->completed()->create(['packing_list_id' => $list->id]);
        PackingItem::factory()->count($notPacked)->create(['packing_list_id' => $list->id]);
    }

    // ——— Как добраться ———

    public function test_transport_step_needs_the_way_there(): void
    {
        $trip = $this->makeTrip();
        $this->addRide($trip, 'back');

        $step = $this->readiness($trip)->step('transport');

        $this->assertFalse($step['ready']);
        $this->assertSame(['Добавьте дорогу туда'], $this->hintTexts($step));
    }

    public function test_transport_step_needs_the_way_back(): void
    {
        $trip = $this->makeTrip();
        $this->addRide($trip, 'to');

        $step = $this->readiness($trip)->step('transport');

        $this->assertFalse($step['ready']);
        $this->assertSame(['Добавьте дорогу обратно (или дальше, в следующий город)'], $this->hintTexts($step));
    }

    public function test_transport_step_is_ready_with_way_there_and_way_back_or_onward(): void
    {
        $roundTrip = $this->makeTrip();
        $this->addRide($roundTrip, 'to');
        $this->addRide($roundTrip, 'back');

        $goingOnward = $this->makeTrip();
        $this->addRide($goingOnward, 'to');
        $this->addRide($goingOnward, 'onward');

        $this->assertTrue($this->readiness($roundTrip)->step('transport')['ready']);
        $this->assertTrue($this->readiness($goingOnward)->step('transport')['ready']);
    }

    public function test_warning_when_the_way_there_is_far_from_the_trip_start(): void
    {
        $trip = $this->makeTrip();
        $this->addRide($trip, 'to', ['departure_at' => '2026-06-12 08:00', 'arrival_at' => '2026-06-12 12:00']);
        $this->addRide($trip, 'back');

        $step = $this->readiness($trip)->step('transport');

        // Предупреждение мягкое: шаг всё равно готов.
        $this->assertTrue($step['ready']);
        $this->assertSame(
            ['Дорога туда 12 июня, а поездка начинается 15 июня — проверьте даты'],
            $this->hintTexts($step, 'warnings'),
        );
    }

    public function test_night_train_the_day_before_the_start_gives_no_warning(): void
    {
        $trip = $this->makeTrip();
        $this->addRide($trip, 'to', ['departure_at' => '2026-06-14 22:00', 'arrival_at' => '2026-06-15 07:00']);

        $this->assertSame([], $this->readiness($trip)->step('transport')['warnings']);
    }

    // ——— Где жить ———

    public function test_accommodation_step_of_a_draft_asks_for_dates(): void
    {
        $trip = $this->makeTrip(['start_date' => null, 'end_date' => null, 'days_count' => 3]);

        $step = $this->readiness($trip)->step('accommodation');

        $this->assertFalse($step['ready']);
        $this->assertSame(['Укажите даты, чтобы проверить ночи'], $this->hintTexts($step));
    }

    public function test_one_day_trip_needs_no_accommodation(): void
    {
        $trip = $this->makeTrip(['end_date' => '2026-06-15']);

        $this->assertTrue($this->readiness($trip)->step('accommodation')['ready']);
    }

    public function test_accommodation_step_is_ready_when_every_night_is_covered(): void
    {
        $trip = $this->makeTrip();
        // Часы заезда и выезда не важны: заезд днём 15-го, выезд утром 17-го покрывает обе ночи.
        $this->addStay($trip, '2026-06-15 15:00', '2026-06-17 12:00');

        $step = $this->readiness($trip)->step('accommodation');

        $this->assertTrue($step['ready']);
        $this->assertSame('Ночей с жильём: 2 из 2', $step['summary']);
    }

    public function test_night_without_accommodation_is_named_in_a_hint(): void
    {
        $trip = $this->makeTrip();
        $this->addStay($trip, '2026-06-15 15:00', '2026-06-16 12:00');

        $step = $this->readiness($trip)->step('accommodation');

        $this->assertFalse($step['ready']);
        $this->assertSame(['Ночь 2 → 3 (16–17 июня) без жилья'], $this->hintTexts($step));
    }

    public function test_neighbouring_nights_without_accommodation_make_one_hint(): void
    {
        $trip = $this->makeTrip(['start_date' => '2026-06-29', 'end_date' => '2026-07-02']);

        $this->assertSame(
            ['Ночи 1 → 4 (29 июня – 2 июля) без жилья'],
            $this->hintTexts($this->readiness($trip)->step('accommodation')),
        );
    }

    public function test_night_on_the_road_counts_as_covered(): void
    {
        $trip = $this->makeTrip();
        $this->addStay($trip, '2026-06-15 15:00', '2026-06-16 12:00');
        // Ночной поезд: уехал 16-го вечером, приехал 17-го утром.
        $this->addRide($trip, 'back', ['departure_at' => '2026-06-16 23:00', 'arrival_at' => '2026-06-17 07:00']);

        $this->assertTrue($this->readiness($trip)->step('accommodation')['ready']);
    }

    public function test_accommodation_without_dates_covers_no_night(): void
    {
        $trip = $this->makeTrip(['end_date' => '2026-06-16']);
        $this->addStay($trip, '2026-06-15 15:00', '2026-06-16 12:00', ['check_in' => null]);

        $this->assertSame([1], $this->readiness($trip)->uncoveredNights());
    }

    // ——— Что взять ———

    public function test_packing_step_is_not_ready_with_an_empty_list(): void
    {
        $step = $this->readiness($this->makeTrip())->step('packing');

        $this->assertFalse($step['ready']);
        $this->assertSame('Список пуст', $step['summary']);
    }

    public function test_packing_step_needs_every_thing_packed(): void
    {
        $unpacked = $this->makeTrip();
        $this->addPackingItems($unpacked, packed: 2, notPacked: 1);

        $packed = $this->makeTrip();
        $this->addPackingItems($packed, packed: 3, notPacked: 0);

        $step = $this->readiness($unpacked)->step('packing');
        $this->assertFalse($step['ready']);
        $this->assertSame(['Осталось собрать вещей: 1 из 3'], $this->hintTexts($step));

        $this->assertTrue($this->readiness($packed)->step('packing')['ready']);
        $this->assertSame('Собрано: 3 из 3', $this->readiness($packed)->step('packing')['summary']);
    }

    // ——— Что сделать заранее ———

    public function test_advance_step_is_ready_when_there_are_no_tasks(): void
    {
        $step = $this->readiness($this->makeTrip())->step('advance');

        $this->assertTrue($step['ready']);
        $this->assertSame('Дел нет', $step['summary']);
    }

    public function test_ticket_not_bought_is_a_task_with_the_departure_date(): void
    {
        $trip = $this->makeTrip();
        $this->addRide($trip, 'to', ['is_booked' => false]);
        $this->addRide($trip, 'back', ['is_booked' => true]);

        $step = $this->readiness($trip)->step('advance');

        $this->assertFalse($step['ready']);
        $this->assertSame('Сделано: 1 из 2', $step['summary']);
        $this->assertSame(['Купить билет: Поезд Москва → Казань — до 15 июня'], $this->hintTexts($step));
        $this->assertSame('transport', $step['hints'][0]['tab']);
    }

    public function test_own_car_needs_no_ticket(): void
    {
        $trip = $this->makeTrip();
        $this->addRide($trip, 'to', ['type' => 'own_car', 'is_booked' => false]);

        $this->assertSame([], $this->readiness($trip)->advanceTasks());
    }

    public function test_accommodation_not_booked_is_a_task_except_staying_with_friends(): void
    {
        $trip = $this->makeTrip();
        $this->addStay($trip, '2026-06-15 15:00', '2026-06-16 12:00', ['is_booked' => false]);
        $this->addStay($trip, '2026-06-16 15:00', '2026-06-17 12:00', ['type' => 'friends', 'name' => 'У тёти Веры', 'is_booked' => false]);

        $step = $this->readiness($trip)->step('advance');

        $this->assertSame(['Забронировать: Отель «Волга» — до 15 июня'], $this->hintTexts($step));
        $this->assertSame('accommodation', $step['hints'][0]['tab']);
    }

    // ——— План по дням ———

    public function test_plan_step_needs_a_place_on_every_day(): void
    {
        $trip = $this->makeTrip();
        $trip->days()->first()->addPlace(Place::factory()->create());

        $step = $this->readiness($trip)->step('plan');

        $this->assertFalse($step['ready']);
        $this->assertSame(['Дни 2–3 (16–17 июня) без мест'], $this->hintTexts($step));
        $this->assertSame('Дней с местами: 1 из 3', $step['summary']);

        foreach ($trip->days()->where('day_number', '>', 1)->get() as $day) {
            $day->addPlace(Place::factory()->create());
        }

        $this->assertTrue($this->readiness($trip)->step('plan')['ready']);
    }

    public function test_plan_hint_of_a_draft_has_no_dates(): void
    {
        $trip = $this->makeTrip(['start_date' => null, 'end_date' => null, 'days_count' => 2]);
        $trip->days()->first()->addPlace(Place::factory()->create());

        $this->assertSame(['День 2 без мест'], $this->hintTexts($this->readiness($trip)->step('plan')));
    }

    // ——— Готовность ———

    public function test_each_ready_step_adds_twenty_percent(): void
    {
        $trip = $this->makeTrip(['end_date' => '2026-06-15']);

        // Поездка на 1 день без записей: готовы только «Где жить» (ночей нет) и «Заранее» (дел нет).
        $this->assertSame(40, $this->readiness($trip)->percent());

        $trip->days()->first()->addPlace(Place::factory()->create());
        $this->addPackingItems($trip, packed: 1, notPacked: 0);
        $this->addRide($trip, 'to');
        $this->addRide($trip, 'back', ['departure_at' => '2026-06-15 18:00', 'arrival_at' => '2026-06-15 22:00']);

        $readiness = $this->readiness($trip);
        $this->assertSame(100, $readiness->percent());
        $this->assertSame(
            ['transport', 'accommodation', 'packing', 'advance', 'plan'],
            array_column($readiness->toArray()['steps'], 'key'),
        );
    }
}
