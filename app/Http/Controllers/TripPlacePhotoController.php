<?php

namespace App\Http\Controllers;

use App\Console\Commands\FetchPlacePhotos;
use App\Http\Requests\PhotoRequest;
use App\Models\Trip;
use App\Models\TripPlace;
use App\Services\CityCover;
use App\Services\PhotoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Photo of a place in the "Изменить" window of the day plan: upload your own or go back to the one from Wikipedia.
 * Every place belongs to one user (it is created on saving from the map), so changing its photo
 * does not touch anybody else's trips.
 */
class TripPlacePhotoController extends Controller
{
    public function __construct(private PhotoStorage $photos) {}

    public function store(PhotoRequest $request, Trip $trip, TripPlace $tripPlace): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $place = $tripPlace->place;
        $oldImage = $place->image;

        $place->update(['image' => $this->photos->store($request->file('photo'), 'places')]);
        $this->photos->delete($oldImage);

        Inertia::flash('toast', 'Фото места обновлено.');

        return back();
    }

    /**
     * Remove the uploaded photo and look for one in Wikimedia again (as on the map); not found — the card shows a placeholder.
     */
    public function destroy(Trip $trip, TripPlace $tripPlace, CityCover $wikimedia): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $place = $tripPlace->place;

        $this->photos->delete($place->image);
        $place->update([
            'image' => $wikimedia->find($place->name, FetchPlacePhotos::Width, $place->latitude, $place->longitude),
        ]);

        Inertia::flash('toast', 'Своё фото удалено.');

        return back();
    }
}
