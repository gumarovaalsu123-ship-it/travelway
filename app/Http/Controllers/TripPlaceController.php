<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTripPlaceRequest;
use App\Models\Place;
use App\Models\TripDay;
use App\Models\TripPlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TripPlaceController extends Controller
{
    public function store(StoreTripPlaceRequest $request, Place $place): RedirectResponse
    {
        $day = TripDay::findOrFail($request->validated('trip_day_id'));
        $day->addPlace($place, $request->validated('start_time'));

        Inertia::flash('toast', "Место добавлено в план: день {$day->day_number}.");

        return back();
    }

    public function destroy(Request $request, TripPlace $tripPlace): RedirectResponse
    {
        abort_unless($tripPlace->trip->user_id === $request->user()->id, 403);

        $place = $tripPlace->place;
        $tripPlace->delete();
        $place->deleteIfUnused();

        Inertia::flash('toast', 'Место удалено из плана.');

        return back();
    }
}
