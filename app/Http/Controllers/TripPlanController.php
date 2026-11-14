<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeTripPlaceDayRequest;
use App\Http\Requests\UpdateTripDayRequest;
use App\Http\Requests\UpdateTripPlaceRequest;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
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
    public function index(Request $request, Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        $days = $trip->days()->withCount('tripPlaces')->get();
        $selectedDay = $this->selectedDay($trip, $days, $request->integer('day'));

        // Места выбранного дня вместе с местом и его категорией — одним запросом на связь, без N+1.
        $selectedDay?->load('tripPlaces.place.category');

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

        $tripPlace->update($request->validated());

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

        return back();
    }

    /**
     * Move the place to the end of another day of the same trip.
     */
    public function changeDay(ChangeTripPlaceDayRequest $request, Trip $trip, TripPlace $tripPlace): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $day = $trip->days()->findOrFail($request->validated('trip_day_id'));
        $tripPlace->moveToDay($day);

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

        Inertia::flash('toast', 'Места упорядочены по времени.');

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
