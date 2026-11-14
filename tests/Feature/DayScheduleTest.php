<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Transport;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Models\User;
use App\Services\DaySchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * "Рассчитать время" on the "Day plan" tab. The routing service (OSRM) is never called for real:
 * Http::fake answers instead of it, preventStrayRequests fails the test on any other request.
 */
class DayScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->trip = Trip::factory()->for($this->user)->create([
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-12',
        ]);
    }

    private function day(int $number): TripDay
    {
        return $this->trip->days()->where('day_number', $number)->sole();
    }

    /**
     * SQLite keeps time as it was saved ("10:00"), MySQL returns "10:00:00" — compare only hours and minutes.
     */
    private function time(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }

    private function url(string $path): string
    {
        return "/my-trips/{$this->trip->id}{$path}";
    }

    /**
     * Add a place of the given category to the day; coordinates do not matter — the fake server answers anyway.
     */
    private function addPlace(TripDay $day, string $categorySlug, string $name = 'Место'): TripPlace
    {
        $place = Place::factory()->create([
            'name' => $name,
            'category_id' => PlaceCategory::query()->where('slug', $categorySlug)->value('id'),
        ]);

        return $day->addPlace($place);
    }

    /**
     * Answer of OSRM: one leg per pair of neighbouring points.
     *
     * @param  array<int, array{0: int, 1: int}>  $legs  [minutes, meters] of each leg
     * @return array<string, mixed>
     */
    private function osrm(array $legs): array
    {
        return [
            'code' => 'Ok',
            'routes' => [[
                'legs' => array_map(fn (array $leg) => ['duration' => $leg[0] * 60, 'distance' => $leg[1]], $legs),
            ]],
        ];
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $walkLegs
     * @param  array<int, array{0: int, 1: int}>  $carLegs
     */
    private function fakeRouting(array $walkLegs, array $carLegs = []): void
    {
        Http::fake([
            'routing.openstreetmap.de/routed-foot/*' => Http::response($this->osrm($walkLegs)),
            'routing.openstreetmap.de/routed-car/*' => Http::response($this->osrm($carLegs)),
        ]);
    }

    private function calculate(TripDay $day, array $data = []): TestResponse
    {
        return $this->actingAs($this->user)->post($this->url("/days/{$day->id}/schedule"), $data);
    }

    // --- Время на месте ---

    public function test_stay_minutes_default_by_category(): void
    {
        $this->assertSame(120, TripPlace::defaultStayMinutes('museum'));
        $this->assertSame(60, TripPlace::defaultStayMinutes('restaurant'));
        $this->assertSame(90, TripPlace::defaultStayMinutes('park'));
        $this->assertSame(30, TripPlace::defaultStayMinutes('viewpoint'));
        $this->assertSame(60, TripPlace::defaultStayMinutes('other'));
        $this->assertSame(60, TripPlace::defaultStayMinutes(null));
    }

    // --- Расчёт расписания ---

    public function test_schedule_starts_at_ten_and_adds_stay_and_walking_time(): void
    {
        $day = $this->day(1);
        $museum = $this->addPlace($day, 'museum');
        $cafe = $this->addPlace($day, 'cafe');
        $this->fakeRouting([[12, 900]]);

        $this->calculate($day)->assertSessionHasNoErrors()->assertRedirect();

        $museum->refresh();
        $cafe->refresh();
        // Жилья нет — день начинается с первого места, дороги к нему нет.
        $this->assertSame(['10:00', '12:00', null], [$this->time($museum->start_time), $this->time($museum->end_time), $museum->travel_minutes]);
        // 12 минут пешком после музея, кофейня — 1 час.
        $this->assertSame(['12:12', '13:12', 12, 'walk'], [$this->time($cafe->start_time), $this->time($cafe->end_time), $cafe->travel_minutes, $cafe->calculated_mode]);
        // Машина не понадобилась — к серверу был один запрос.
        Http::assertSentCount(1);
    }

    public function test_start_time_of_the_day_is_saved_and_own_stay_minutes_are_used(): void
    {
        $day = $this->day(1);
        $museum = $this->addPlace($day, 'museum');
        $museum->update(['stay_minutes' => 45]);
        $this->addPlace($day, 'park');
        $this->fakeRouting([[5, 400]]);

        $this->calculate($day, ['start_time' => '09:30'])->assertSessionHasNoErrors();

        $this->assertSame('09:30', $this->time($day->fresh()->start_time));
        $this->assertSame(['09:30', '10:15'], [$this->time($museum->fresh()->start_time), $this->time($museum->fresh()->end_time)]);
    }

    public function test_far_segment_goes_by_public_transport_with_estimate_and_yandex_link(): void
    {
        $day = $this->day(1);
        $this->addPlace($day, 'viewpoint');
        $museum = $this->addPlace($day, 'museum');
        // 45 минут пешком (5 км) — дальше 20 минут; своего авто нет → общественный: 14 × 1,5 + 10 = 31.
        $this->fakeRouting([[45, 5000]], [[14, 6000]]);

        $this->calculate($day)->assertSessionHasNoErrors();

        $museum->refresh();
        $this->assertSame([31, 'transit'], [$museum->travel_minutes, $museum->calculated_mode]);
        // Смотровая 10:00–10:30, плюс 31 минута дороги.
        $this->assertSame('11:01', $this->time($museum->start_time));

        $this->actingAs($this->user)->get($this->url('/plan?day=1'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.places.1.segment.mode', 'transit')
                ->where('selectedDay.places.1.segment.approximate', true)
                ->where('selectedDay.places.1.segment.yandexUrl', fn (string $url) => str_starts_with($url, 'https://yandex.ru/maps/?rtext=') && str_ends_with($url, '&rtt=mt')));
    }

    public function test_far_segment_goes_by_car_when_traveller_came_in_own_car(): void
    {
        Transport::factory()->for($this->trip)->create(['type' => 'own_car', 'direction' => 'to']);
        $day = $this->day(2);
        $this->addPlace($day, 'viewpoint');
        $museum = $this->addPlace($day, 'museum');
        $this->fakeRouting([[45, 5000]], [[14, 6000]]);

        $this->calculate($day)->assertSessionHasNoErrors();

        $this->assertSame([14, 'car'], [$museum->fresh()->travel_minutes, $museum->fresh()->calculated_mode]);
    }

    public function test_choice_of_mode_by_walking_time(): void
    {
        $this->assertSame('walk', DaySchedule::automaticMode(20, hasOwnCar: false));
        $this->assertSame('transit', DaySchedule::automaticMode(21, hasOwnCar: false));
        $this->assertSame('car', DaySchedule::automaticMode(21, hasOwnCar: true));

        // Общественный транспорт до 1,5 км — пешком, дальше — машина × 1,5 + 10 минут.
        $this->assertSame(['mode' => 'walk', 'minutes' => 18], DaySchedule::segment('transit', ['minutes' => 18, 'meters' => 1500], null));
        $this->assertSame(['mode' => 'transit', 'minutes' => 25], DaySchedule::segment('transit', ['minutes' => 30, 'meters' => 2500], ['minutes' => 10, 'meters' => 2600]));
        // Такси — время как у машины.
        $this->assertSame(['mode' => 'taxi', 'minutes' => 10], DaySchedule::segment('taxi', ['minutes' => 30, 'meters' => 2500], ['minutes' => 10, 'meters' => 2600]));
    }

    public function test_mode_chosen_by_traveller_wins_over_automatic_choice(): void
    {
        $day = $this->day(1);
        $this->addPlace($day, 'cafe');
        $museum = $this->addPlace($day, 'museum');
        $museum->update(['travel_mode' => 'taxi']);
        // Пешком всего 8 минут, но человек выбрал такси — нужен и запрос для машины.
        $this->fakeRouting([[8, 600]], [[3, 700]]);

        $this->calculate($day)->assertSessionHasNoErrors();

        $this->assertSame([3, 'taxi'], [$museum->fresh()->travel_minutes, $museum->fresh()->calculated_mode]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/routed-car/'));
    }

    public function test_day_starts_and_ends_at_lodging(): void
    {
        Accommodation::factory()->for($this->trip)->create([
            'name' => 'Отель «Волга»',
            'check_in' => '2026-12-10 14:00',
            'check_out' => '2026-12-12 12:00',
            'latitude' => 55.79,
            'longitude' => 49.12,
        ]);
        $day = $this->day(2);
        $park = $this->addPlace($day, 'park');
        // Жильё → парк 15 мин, парк → жильё 15 мин.
        $this->fakeRouting([[15, 1100], [15, 1100]]);

        $this->calculate($day)->assertSessionHasNoErrors();

        $this->assertSame(['10:15', '11:45', 15], [$this->time($park->fresh()->start_time), $this->time($park->fresh()->end_time), $park->fresh()->travel_minutes]);
        $this->assertSame([15, 'walk'], [$day->fresh()->return_minutes, $day->fresh()->return_mode]);

        $this->actingAs($this->user)->get($this->url('/plan?day=2'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.returnSegment.lodging', 'Отель «Волга»')
                ->where('selectedDay.endTime', '12:00')
                ->where('selectedDay.travelMinutes', 30)
                ->where('selectedDay.stayMinutes', 90));
    }

    public function test_first_day_starts_after_arrival(): void
    {
        Transport::factory()->for($this->trip)->create([
            'type' => 'train',
            'direction' => 'to',
            'departure_at' => '2026-12-10 06:00',
            'arrival_at' => '2026-12-10 13:30',
        ]);
        $day = $this->day(1);
        $cafe = $this->addPlace($day, 'cafe');

        // Одно место без жилья — отрезков нет, к серверу маршрутов даже не обращаемся.
        $this->calculate($day)->assertSessionHasNoErrors();

        $this->assertSame('13:30', $this->time($cafe->fresh()->start_time));
        Http::assertNothingSent();
    }

    public function test_last_day_warns_when_less_than_an_hour_before_departure(): void
    {
        Transport::factory()->for($this->trip)->create([
            'type' => 'train',
            'direction' => 'back',
            'departure_at' => '2026-12-12 12:30',
            'arrival_at' => '2026-12-12 20:00',
        ]);
        $day = $this->day(3);
        $this->addPlace($day, 'museum')->update(['start_time' => '10:00', 'end_time' => '12:00']);

        $this->actingAs($this->user)->get($this->url('/plan?day=3'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.warnings.0', fn (string $warning) => str_contains($warning, '12:30')));
    }

    // --- Закреплённое время ---

    public function test_locked_time_is_not_moved_and_hints_show_late_and_free_time(): void
    {
        $day = $this->day(1);
        $this->addPlace($day, 'museum'); // 10:00–12:00
        $lunch = $this->addPlace($day, 'restaurant');
        $lunch->update(['start_time' => '12:00', 'end_time' => '13:00', 'time_locked' => true]);
        $show = $this->addPlace($day, 'attraction');
        $show->update(['start_time' => '14:00', 'time_locked' => true]);
        $this->fakeRouting([[20, 1400], [10, 700]]);

        $this->calculate($day)->assertSessionHasNoErrors();

        // Время не сдвинулось, хотя дорога до обеда 20 минут.
        $this->assertSame(['12:00', '13:00', 20], [$this->time($lunch->fresh()->start_time), $this->time($lunch->fresh()->end_time), $lunch->fresh()->travel_minutes]);
        $this->assertSame('14:00', $this->time($show->fresh()->start_time));

        $this->actingAs($this->user)->get($this->url('/plan?day=1'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.places.1.lateMinutes', 20)
                ->where('selectedDay.places.1.freeMinutes', null)
                // После обеда в 13:00 + 10 минут дороги — свободно до 14:00.
                ->where('selectedDay.places.2.freeMinutes', 50)
                ->where('selectedDay.places.2.timeLocked', true));
    }

    // --- Сервис не ответил ---

    public function test_failed_routing_service_keeps_saved_schedule(): void
    {
        $day = $this->day(1);
        $museum = $this->addPlace($day, 'museum');
        $cafe = $this->addPlace($day, 'cafe');
        $cafe->update(['start_time' => '15:00', 'end_time' => '16:00', 'travel_minutes' => 7, 'calculated_mode' => 'walk']);
        Http::fake(['routing.openstreetmap.de/*' => Http::response('Server error', 502)]);

        $this->calculate($day, ['start_time' => '09:00'])->assertSessionHasErrors('schedule');

        $this->assertNull($museum->fresh()->start_time);
        $this->assertSame(['15:00', 7], [$this->time($cafe->fresh()->start_time), $cafe->fresh()->travel_minutes]);
    }

    public function test_unreachable_routing_service_keeps_saved_schedule(): void
    {
        $day = $this->day(1);
        $this->addPlace($day, 'museum');
        $cafe = $this->addPlace($day, 'cafe');
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->calculate($day)->assertSessionHasErrors('schedule');

        $this->assertNull($cafe->fresh()->start_time);
    }

    // --- Изменение порядка ---

    public function test_moving_a_place_forgets_old_travel_times(): void
    {
        $day = $this->day(1);
        $first = $this->addPlace($day, 'museum');
        $second = $this->addPlace($day, 'cafe');
        $second->update(['travel_minutes' => 12, 'calculated_mode' => 'walk']);
        $day->update(['return_minutes' => 5, 'return_mode' => 'walk']);

        $this->actingAs($this->user)->patch($this->url("/trip-places/{$second->id}/move"), ['direction' => 'up']);

        $this->assertNull($second->fresh()->travel_minutes);
        $this->assertNull($day->fresh()->return_minutes);
        $this->assertSame(2, $first->fresh()->visit_order);
    }

    public function test_changing_travel_mode_forgets_old_travel_time(): void
    {
        $day = $this->day(1);
        $visit = $this->addPlace($day, 'cafe');
        $visit->update(['travel_minutes' => 12, 'calculated_mode' => 'walk']);

        $this->actingAs($this->user)
            ->patch($this->url("/trip-places/{$visit->id}"), ['travel_mode' => 'car', 'stay_minutes' => 40])
            ->assertSessionHasNoErrors();

        $visit->refresh();
        $this->assertSame(['car', 40, null], [$visit->travel_mode, $visit->stay_minutes, $visit->travel_minutes]);
    }

    // --- Валидация и доступ ---

    public function test_schedule_fields_validation(): void
    {
        $visit = $this->addPlace($this->day(1), 'cafe');
        $url = $this->url("/trip-places/{$visit->id}");
        $this->actingAs($this->user);

        $this->patch($url, ['stay_minutes' => 0])->assertSessionHasErrors('stay_minutes');
        $this->patch($url, ['stay_minutes' => 721])->assertSessionHasErrors('stay_minutes');
        $this->patch($url, ['stay_minutes' => 'долго'])->assertSessionHasErrors('stay_minutes');
        $this->patch($url, ['travel_mode' => 'helicopter'])->assertSessionHasErrors('travel_mode');
        $this->patch($url, ['time_locked' => true, 'start_time' => ''])->assertSessionHasErrors('start_time');
        $this->patch($url, ['time_locked' => 'maybe'])->assertSessionHasErrors('time_locked');

        $this->post($this->url("/days/{$this->day(1)->id}/schedule"), ['start_time' => '25:00'])->assertSessionHasErrors('start_time');

        // Пустые значения — «по категории» и «автоматически».
        $this->patch($url, ['stay_minutes' => '', 'travel_mode' => '', 'time_locked' => false])->assertSessionHasNoErrors();
        $this->assertNull($visit->fresh()->stay_minutes);
        $this->assertNull($visit->fresh()->travel_mode);
    }

    public function test_stranger_cannot_calculate_schedule(): void
    {
        $this->actingAs(User::factory()->create())
            ->post($this->url("/days/{$this->day(1)->id}/schedule"))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_plan_shows_default_stay_and_not_calculated_day(): void
    {
        $this->addPlace($this->day(1), 'museum');

        $this->actingAs($this->user)->get($this->url('/plan?day=1'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDay.places.0.stayMinutes', null)
                ->where('selectedDay.places.0.defaultStayMinutes', 120)
                ->where('selectedDay.places.0.segment', null)
                ->where('selectedDay.scheduleStartTime', '10:00')
                ->where('selectedDay.isCalculated', false));

        Http::assertNothingSent();
    }
}
