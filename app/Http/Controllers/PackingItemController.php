<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackingItemRequest;
use App\Models\PackingItem;
use App\Models\PackingList;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Things in the packing list. Routes use scoped bindings, so a thing of another trip gives 404.
 */
class PackingItemController extends Controller
{
    public function store(PackingItemRequest $request, Trip $trip, PackingList $packingList): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $packingList->addItem($request->validated('name'), $request->validated('note'));

        return back();
    }

    /**
     * Rename the thing or change its note.
     */
    public function update(PackingItemRequest $request, Trip $trip, PackingItem $packingItem): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $packingItem->update($request->validated());

        return back();
    }

    /**
     * Tick or untick "packed".
     */
    public function toggle(Trip $trip, PackingItem $packingItem): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $packingItem->update(['is_completed' => ! $packingItem->is_completed]);

        return back();
    }

    public function destroy(Trip $trip, PackingItem $packingItem): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $packingItem->delete();

        return back();
    }
}
