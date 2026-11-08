<?php

namespace App\Http\Controllers;

use App\Models\PackingItem;
use App\Models\PackingList;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Packing list" tab of the trip page.
 */
class PackingController extends Controller
{
    public function index(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        $trip->load('packingLists.items');

        return Inertia::render('Trips/Packing', [
            'trip' => $trip->toHeader(),
            'lists' => $trip->packingLists->map(fn (PackingList $list) => [
                'id' => $list->id,
                'title' => $list->title,
                'items' => $list->items->map(fn (PackingItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'note' => $item->note,
                    'isCompleted' => $item->is_completed,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Add the standard set (PackingList::Defaults). Groups and things the trip already has are skipped,
     * so pressing the button twice does not create duplicates.
     */
    public function storeDefaults(Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        DB::transaction(function () use ($trip) {
            foreach (PackingList::Defaults as $title => $names) {
                $list = $trip->packingLists()->firstOrCreate(
                    ['title' => $title],
                    ['position' => (int) $trip->packingLists()->max('position') + 1],
                );

                $existingNames = $list->items()->pluck('name')->all();

                foreach (array_diff($names, $existingNames) as $name) {
                    $list->addItem($name);
                }
            }
        });

        Inertia::flash('toast', 'Стандартный набор вещей добавлен.');

        return back();
    }
}
