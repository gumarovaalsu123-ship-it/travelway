<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalculateDayScheduleRequest;
use App\Http\Requests\StoreDayPlaceRequest;
use App\Http\Requests\UpdateTripPlaceRequest;
use App\Models\Accommodation;
use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Services\DaySchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Plan" tab of the trip page: the places of one chosen day, their order, time and notes,
 * and next to them the map of this day (numbered stops, lodging, route line — see Components/Trip/DayMap.jsx).
 * Change routes use scoped bindings (see routes/web.php), so a day or place of another trip gives 404.
 * After every change we return back(), so the same ?day=N stays open.
 */
class TripPlanController extends Controller
{
    public function index(Request $request, Trip $trip, DaySchedule $schedule): Response
    {
        Gate::authorize('view', $trip);

        // Названия мест каждого дня — для выпадающего списка дней (только id и name, одним запросом).
        $days = $trip->days()->withCount('tripPlaces')->with('tripPlaces.place:id,name')->get();

        // «Показать на карте» на вкладке «Проживание» ведёт сюда с ?lodging=id: это жильё подсвечиваем на карте.
        // Ищем только среди жилья этой поездки и только с координатами — иначе показать нечего.
        $focusLodging = $request->filled('lodging')
            ? $trip->accommodations()->whereKey($request->integer('lodging'))->whereNotNull('latitude')->first()
            : null;

        // Без ?day= при переходе к жилью открываем день заезда в него.
        $dayNumber = $request->integer('day') ?: $this->checkInDayNumber($trip, $focusLodging);
        $selectedDay = $trip->dayByNumber($days, $dayNumber);

        // Места выбранного дня вместе с местом и его категорией — одним запросом на связь, без N+1.
        // Жильё и транспорт нужны расписанию: откуда начинается день и когда уезжать.
        $selectedDay?->load('tripPlaces.place.category', 'trip.accommodations', 'trip.transports');
        $summary = $selectedDay ? $schedule->summary($selectedDay) : null;

        return Inertia::render('Trips/Plan', [
            'trip' => $trip->toHeader(),
            'days' => $days->map(fn (TripDay $day) => [
                'id' => $day->id,
                'number' => $day->day_number,
                'date' => $day->date?->toDateString(),
                'placesCount' => $day->trip_places_count,
                'placeNames' => $day->tripPlaces->pluck('place.name')->values(),
            ])->values(),
            'selectedDay' => $selectedDay ? [
                'id' => $selectedDay->id,
                'number' => $selectedDay->day_number,
                'date' => $selectedDay->date?->toDateString(),
                // Начало дня, которое выбрал человек (null — по умолчанию), и то, от которого считается расписание.
                'startTime' => $selectedDay->start_time ? substr($selectedDay->start_time, 0, 5) : null,
                'scheduleStartTime' => $summary['startTime'],
                'isCalculated' => $summary['isCalculated'],
                'endTime' => $summary['endTime'],
                'returnSegment' => $summary['returnSegment'],
                'travelMinutes' => $summary['travelMinutes'],
                'stayMinutes' => $summary['stayMinutes'],
                'warnings' => $summary['warnings'],
                // Для карты: жильё, откуда день начинается и куда возвращаемся вечером (может не быть),
                // и способ на обратном пути — по нему рисуется последний отрезок линии.
                'morningLodging' => $this->lodging($schedule->morningLodging($selectedDay)),
                'eveningLodging' => $this->lodging($schedule->eveningLodging($selectedDay)),
                'returnMode' => $selectedDay->return_mode,
                // Места идут по visit_order (так задана связь tripPlaces): номер в списке = номер маркера на карте.
                'places' => $selectedDay->tripPlaces->map(fn (TripPlace $visit) => [
                    'id' => $visit->id,
                    'placeId' => $visit->place_id,
                    'name' => $visit->place->name,
                    'address' => $visit->place->address,
                    // Координаты нужны подсказкам Overpass: «Что по пути?» и «Пора пообедать?» ищут вокруг мест.
                    'lat' => $visit->place->latitude,
                    'lng' => $visit->place->longitude,
                    'category' => $visit->place->category?->name,
                    'image' => $visit->place->image,
                    // В базе время хранится как «10:00:00», на странице нужно «10:00».
                    'startTime' => $visit->start_time ? substr($visit->start_time, 0, 5) : null,
                    'endTime' => $visit->end_time ? substr($visit->end_time, 0, 5) : null,
                    'note' => $visit->note,
                    'stayMinutes' => $visit->stay_minutes,
                    'defaultStayMinutes' => TripPlace::defaultStayMinutes($visit->place->category?->slug),
                    'travelMode' => $visit->travel_mode,
                    // Способ на отрезке к месту для линии на карте: рассчитанный, иначе выбранный, иначе неизвестен.
                    'mode' => $visit->calculated_mode ?? $visit->travel_mode,
                    'timeLocked' => $visit->time_locked,
                    // Билет/бронь (этап 3): вопрос у места зависит от категории, см. bookingQuestion в Plan.jsx.
                    'categorySlug' => $visit->place->category?->slug,
                    'needsBooking' => $visit->needs_booking,
                    'isBooked' => $visit->is_booked,
                    // Отрезок от предыдущей точки до этого места и подсказки «не успеваете» / «свободное окно».
                    ...$summary['places'][$visit->id],
                ])->values(),
            ] : null,
            // Категории для мест, найденных подсказками: браузер переводит тег OSM в slug, сервер ждёт id.
            'categories' => PlaceCategory::query()->orderBy('id')->get(['id', 'slug']),
            'favorites' => $request->user()->favoritePlaces()
                ->with('category')
                ->orderBy('name')
                ->get()
                ->map(fn (Place $place) => [
                    'id' => $place->id,
                    'name' => $place->name,
                    'address' => $place->address,
                    'category' => $place->category?->name,
                    // По координатам Plan.jsx оставляет в списке только места в городе (стране) поездки.
                    'lat' => $place->latitude,
                    'lng' => $place->longitude,
                ]),
            'focusLodging' => $this->lodging($focusLodging),
            // Центр карты для пустого дня, чтобы было куда кликнуть и добавить первое место:
            // любое место или жильё этой поездки. null — браузер найдёт город поездки по названию.
            'mapCenter' => $this->mapCenter($trip),
        ]);
    }

    /**
     * Номер дня поездки, в который заезжаем в жильё (заезд накануне начала — день 1). 0 — день неизвестен.
     */
    private function checkInDayNumber(Trip $trip, ?Accommodation $lodging): int
    {
        if ($lodging?->check_in === null || ! $trip->hasDates()) {
            return 0;
        }

        $daysFromStart = (int) $trip->start_date->diffInDays($lodging->check_in->copy()->startOfDay(), false);

        return max($daysFromStart, 0) + 1;
    }

    /**
     * Любая точка поездки с координатами: сначала места из плана, потом жильё.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function mapCenter(Trip $trip): ?array
    {
        $place = $trip->tripPlaces()->with('place')->first()?->place;

        if ($place !== null) {
            return ['lat' => $place->latitude, 'lng' => $place->longitude];
        }

        $lodging = $trip->accommodations()->whereNotNull('latitude')->first();

        return $lodging ? ['lat' => $lodging->latitude, 'lng' => $lodging->longitude] : null;
    }

    /**
     * Жильё как точка на карте.
     *
     * @return array{id: int, name: string, address: ?string, lat: float, lng: float}|null
     */
    private function lodging(?Accommodation $lodging): ?array
    {
        if ($lodging === null) {
            return null;
        }

        return [
            'id' => $lodging->id,
            'name' => $lodging->name,
            'address' => $lodging->address,
            'lat' => $lodging->latitude,
            'lng' => $lodging->longitude,
        ];
    }

    /**
     * The "Сохранить" button of the "Изменить" window: time, time on the place, way to get there, note,
     * the booking question and the day — everything in one request, so nothing typed in the form is lost.
     */
    public function update(UpdateTripPlaceRequest $request, Trip $trip, TripPlace $tripPlace): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $tripPlace->fill($request->safe()->except('trip_day_id'));

        // Сменили способ — старое время в пути к нему больше не подходит, его покажет следующий расчёт.
        if ($tripPlace->isDirty('travel_mode')) {
            $tripPlace->travel_minutes = null;
            $tripPlace->calculated_mode = null;
        }

        // Билет больше не нужен — значит, и «куплено» не о чем.
        if (! $tripPlace->needs_booking) {
            $tripPlace->is_booked = false;
        }

        // Окончание не вводится: это всегда начало + время на месте (так же считает DaySchedule).
        $tripPlace->end_time = $tripPlace->start_time
            ? DaySchedule::formatTime(DaySchedule::toMinutes($tripPlace->start_time) + $tripPlace->stayMinutes())
            : null;

        $newDay = $trip->days()->find($request->validated('trip_day_id'));
        $isMoving = $newDay !== null && $newDay->id !== $tripPlace->trip_day_id;

        // Транзакция: правки и перенос сохраняются вместе или не сохраняются вовсе.
        DB::transaction(function () use ($tripPlace, $newDay, $isMoving) {
            $tripPlace->save();

            if ($isMoving) {
                $previousDay = $tripPlace->day;
                $tripPlace->moveToDay($newDay);

                // Порядок поменялся в обоих днях — старые отрезки соединяли бы не те места.
                $previousDay->forgetTravelTimes();
                $newDay->forgetTravelTimes();
            }
        });

        Inertia::flash('toast', $isMoving ? "Изменения сохранены, место перенесено в день {$newDay->day_number}." : 'Изменения сохранены.');

        return back();
    }

    /**
     * Save a place found by an Overpass hint and put it into the day:
     * "Что по пути?" — between the two places of the segment, "Пора пообедать?" — after the place before lunch.
     */
    public function storePlace(StoreDayPlaceRequest $request, Trip $trip, TripDay $day): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $userId = $request->user()->id;
        $place = Place::findNearbyOfUser($userId, $request->validated('name'), $request->validated('latitude'), $request->validated('longitude'))
            ?? Place::create([
                ...$request->safe()->only(['name', 'category_id', 'address', 'latitude', 'longitude']),
                'user_id' => $userId,
            ]);

        $after = $request->validated('after_trip_place_id')
            ? $day->tripPlaces()->findOrFail($request->validated('after_trip_place_id'))
            : null;

        $day->insertPlaceAfter($place, $after);
        // Между соседями появилось новое место — старые отрезки пути их больше не соединяют.
        $day->forgetTravelTimes();

        Inertia::flash('toast', "«{$place->name}» добавлено в план дня {$day->day_number}.");

        return back();
    }

    /**
     * Buttons ↑ and ↓: swap the place with its neighbour in the same day.
     */
    public function move(Request $request, Trip $trip, TripPlace $tripPlace): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $direction = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ])['direction'];

        if ($direction === 'up') {
            $tripPlace->moveUp();
        } else {
            $tripPlace->moveDown();
        }

        $tripPlace->day->forgetTravelTimes();

        return back();
    }

    /**
     * The "Рассчитать время" button: save the start of the day and build the schedule
     * with travel times from OSRM (see App\Services\DaySchedule).
     */
    public function calculateSchedule(CalculateDayScheduleRequest $request, Trip $trip, TripDay $day, DaySchedule $schedule): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $day->update(['start_time' => $request->validated('start_time')]);

        if (! $schedule->calculate($day)) {
            // Сервис маршрутов не ответил: ничего не меняли, сохранённое расписание осталось как было.
            return back()->withErrors([
                'schedule' => 'Сервис маршрутов сейчас не отвечает. Сохранённое расписание не изменилось — попробуйте позже.',
            ]);
        }

        Inertia::flash('toast', 'Расписание дня рассчитано.');

        return back();
    }
}
