<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackingListRequest;
use App\Models\PackingList;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Groups of the packing list. The group always belongs to {trip}: routes use scoped bindings.
 */
class PackingListController extends Controller
{
    public function store(PackingListRequest $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $trip->packingLists()->create([
            'title' => $request->validated('title'),
            'position' => (int) $trip->packingLists()->max('position') + 1,
        ]);

        return back();
    }

    public function update(PackingListRequest $request, Trip $trip, PackingList $packingList): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $packingList->update($request->validated());

        return back();
    }

    public function destroy(Trip $trip, PackingList $packingList): RedirectResponse
    {
        Gate::authorize('update', $trip);

        // Вещи группы удалит сама база (cascadeOnDelete в миграции packing_items).
        $packingList->delete();

        Inertia::flash('toast', "Группа «{$packingList->title}» удалена.");

        return back();
    }
}
