<?php

namespace App\Http\Controllers;

use App\Http\Requests\PhotoRequest;
use App\Models\Trip;
use App\Services\PhotoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Block "Обложка" in the trip "Settings": upload your own photo or go back to the automatic one (Wikipedia).
 * Like the avatar, the file is sent right after it is chosen, separately from the settings form.
 */
class TripCoverController extends Controller
{
    public function __construct(private PhotoStorage $photos) {}

    public function store(PhotoRequest $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('updateSettings', $trip);

        $oldCover = $trip->cover_image;

        $trip->update(['cover_image' => $this->photos->store($request->file('photo'), 'covers')]);
        $this->photos->delete($oldCover);

        Inertia::flash('toast', 'Обложка обновлена.');

        return back();
    }

    /**
     * Remove the uploaded photo: the cover is looked up in Wikipedia again (or the stock picture is shown).
     */
    public function destroy(Trip $trip): RedirectResponse
    {
        Gate::authorize('updateSettings', $trip);

        $this->photos->delete($trip->cover_image);
        $trip->refreshCover();

        Inertia::flash('toast', 'Вернули обложку по городу.');

        return back();
    }
}
