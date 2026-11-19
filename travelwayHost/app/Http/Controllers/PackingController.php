<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackingHintRequest;
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
    public function index(Trip $trip): Response|RedirectResponse
    {
        Gate::authorize('view', $trip);

        // У текущей поездки вкладки «Вещи» нет (см. Trip::tabs()) — открываем первую вкладку.
        if (! $trip->hasTab('packing')) {
            return to_route('trips.show', $trip);
        }

        $trip->load('packingLists.items', 'transports', 'accommodations');

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
            // Блок «Возможно, пригодится»: считается заново при каждом открытии, в базе не хранится.
            'hints' => PackingList::hintsFor($trip),
        ]);
    }

    /**
     * Add the standard set again (the button is shown only when the list is empty, for example
     * when the user deleted everything). Things the trip already has are skipped.
     */
    public function storeDefaults(Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        DB::transaction(fn () => PackingList::addDefaultsTo($trip));

        Inertia::flash('toast', 'Стандартный набор вещей добавлен.');

        return back();
    }

    /**
     * Add the things chosen in one "Might come in handy" hint to the hint's group (PackingList::Hints).
     * Things the trip already has are skipped again here, in case the page was outdated.
     */
    public function storeHint(PackingHintRequest $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $hint = PackingList::Hints[$request->validated('rule')];
        $existingNames = $trip->packingItems()->pluck('name')->map(fn (string $name) => mb_strtolower($name))->all();
        $newNames = array_filter($request->validated('items'), fn (string $name) => ! in_array(mb_strtolower($name), $existingNames, true));

        if ($newNames !== []) {
            DB::transaction(function () use ($trip, $hint, $newNames) {
                $list = PackingList::firstOrCreateFor($trip, $hint['group']);

                foreach ($newNames as $name) {
                    $list->addItem($name);
                }
            });
        }

        Inertia::flash('toast', "Добавлено в группу «{$hint['group']}».");

        return back();
    }
}
