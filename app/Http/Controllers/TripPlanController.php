<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalculateDayScheduleRequest;
use App\Http\Requests\ChangeTripPlaceDayRequest;
use App\Http\Requests\UpdateTripDayRequest;
use App\Http\Requests\UpdateTripPlaceRequest;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Services\DaySchedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Day plan" tab of the trip page: the places of one chosen day, their order, time and notes.
 * Change routes use scoped bindings (see routes/web.php), so a day or place of another trip gives 404.
 * After every change we return back(), so the same ?day=N stays open.
 */
class TripPlanController extends Controller
{
    public function index(Request $request, Trip $trip, DaySchedule $schedule): Response
    {
        Gate::authorize('view', $trip);

        $days = $trip->days()->withCount('tripPlaces')->get();
        $selectedDay = $this->selectedDay($trip, $days, $request->integer('day'));

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
                'title' => $day->title,
                'placesCount' => $day->trip_places_count,
            ])->values(),
            'selectedDay' => $selectedDay ? [
                'id' => $selectedDay->id,
                'number' => $selectedDay->day_number,
                'date' => $selectedDay->date?->toDateString(),
                'title' => $selectedDay->title,
                // Начало дня, которое выбрал человек (null — по умолчанию), и то, от которого считается расписание.
                'startTime' => $selectedDay->start_time ? substr($selectedDay->start_time, 0, 5) : null,
                'scheduleStartTime' => $summary['startTime'],
                'isCalculated' => $summary['isCalculated'],
                'endTime' => $summary['endTime'],
                'returnSegment' => $summary['returnSegment'],
                'travelMinutes' => $summary['travelMinutes'],
                'stayMinutes' => $summary['stayMinutes'],
                'warnings' => $summary['warnings'],
                'places' => $selectedDay->tripPlaces->map(fn (TripPlace $visit) => [
                    'id' => $visit->id,
                    'placeId' => $visit->place_id,
                    'name' => $visit->place->name,
                    'address' => $visit->place->address,
                    'category' => $visit->place->category?->name,
                    'image' => $visit->place->image,
                    // В базе время хранится как «10:00:00», на странице нужно «10:00».
                    'startTime' => $visit->start_time ? substr($visit->start_time, 0, 5) : null,
                    'endTime' => $visit->end_time ? substr($visit->end_time, 0, 5) : null,
                    'note' => $visit->note,
                    'stayMinutes' => $visit->stay_minutes,
                    'defaultStayMinutes' => TripPlace::defaultStayMinutes($visit->place->category?->slug),
                    'travelMode' => $visit->travel_mode,
                    'timeLocked' => $visit->time_locked,
                    // Билет/бронь (этап 3): вопрос у места зависит от категории, см. bookingQuestion в Plan.jsx.
                    'categorySlug' => $visit->place->category?->slug,
                    'needsBooking' => $visit->needs_booking,
                    'isBooked' => $visit->is_booked,
                    // Отрезок от предыдущей точки до этого места и подсказки «не успеваете» / «свободное окно».
                    ...$summary['places'][$visit->id],
                ])->values(),
            ] : null,
            'favorites' => $request->user()->favoritePlaces()
                ->with('category')
                ->orderBy('name')
                ->get()
                ->map(fn (Place $place) => [
                    'id' => $place->id,
                    'name' => $place->name,
                    'address' => $place->address,
                    'category' => $place->category?->name,
                ]),
        ]);
    }

    /**
     * Rename the day, for example "Знакомство с Алфамой". An empty title means just "День N".
     */
    public function updateDay(UpdateTripDayRequest $request, Trip $trip, TripDay $day): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $day->update($request->validated());

        return back();
    }

    /**
     * Change the visit time and the note of a place in the plan.
     */
    public function update(UpdateTripPlaceRequest $request, Trip $trip, TripPlace $tripPlace): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $tripPlace->fill($request->validated());

        // Сменили способ — старое время в пути к нему больше не подходит, его покажет следующий расчёт.
        if ($tripPlace->isDirty('travel_mode')) {
            $tripPlace->travel_minutes = null;
            $tripPlace->calculated_mode = null;
        }

        $tripPlace->save();

        Inertia::flash('toast', 'Изменения сохранены.');

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
     * Move the place to the end of another day of the same trip.
     */
    public function changeDay(ChangeTripPlaceDayRequest $request, Trip $trip, TripPlace $tripPlace): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $day = $trip->days()->findOrFail($request->validated('trip_day_id'));
        $previousDay = $tripPlace->day;
        $tripPlace->moveToDay($day);

        // Порядок поменялся в обоих днях — старые отрезки соединяли бы не те места.
        $previousDay->forgetTravelTimes();
        $day->forgetTravelTimes();

        Inertia::flash('toast', "Место перенесено в день {$day->day_number}.");

        return back();
    }

    /**
     * Put the places of the day in order of their start time; places without time go to the end.
     */
    public function sortByTime(Trip $trip, TripDay $day): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $day->sortPlacesByTime();
        $day->forgetTravelTimes();

        Inertia::flash('toast', 'Места упорядочены по времени.');

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

    /**
     * The day from ?day=N. If there is no such day (no parameter, garbage, or the trip got shorter),
     * open today's day of a trip that is happening now, otherwise day 1.
     *
     * @param  Collection<int, TripDay>  $days
     */
    protected function selectedDay(Trip $trip, Collection $days, int $requestedNumber): ?TripDay
    {
        $day = $days->firstWhere('day_number', $requestedNumber);

        if ($day === null && $trip->status === Trip::StatusCurrent) {
            // Так же, как «Текущая поездка» на главной: номер сегодняшнего дня от даты начала.
            $todayNumber = (int) $trip->start_date->diffInDays(today()) + 1;
            $day = $days->firstWhere('day_number', $todayNumber);
        }

        return $day ?? $days->first();
    }
}
