<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTripPlaceRequest;
use App\Models\Place;
use App\Models\TripDay;
use App\Models\TripPlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TripPlaceController extends Controller
{
    public function store(StoreTripPlaceRequest $request, Place $place): RedirectResponse
    {
        $day = TripDay::findOrFail($request->validated('trip_day_id'));
        // Что день из поездки этого пользователя, проверил StoreTripPlaceRequest; здесь — что поездка не завершена.
        Gate::authorize('update', $day->trip);

        $day->addPlace($place, $request->validated('start_time'));

        Inertia::flash('toast', "Место добавлено в план: день {$day->day_number}.");

        return back();
    }

    public function destroy(TripPlace $tripPlace): RedirectResponse
    {
        Gate::authorize('update', $tripPlace->trip);

        $place = $tripPlace->place;
        $day = $tripPlace->day;
        $tripPlace->delete();
        $place->deleteIfUnused();
        // Соседи места теперь идут подряд — старые отрезки пути к ним не подходят.
        $day->forgetTravelTimes();

        Inertia::flash('toast', 'Место удалено из плана.');

        return back();
    }
}
