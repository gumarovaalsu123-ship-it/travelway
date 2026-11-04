<?php

namespace App\Http\Controllers;

use App\Models\Place;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FavoritePlaceController extends Controller
{
    public function store(Request $request, Place $place): RedirectResponse
    {
        $request->user()->favoritePlaces()->syncWithoutDetaching([
            $place->id => ['created_at' => now()],
        ]);

        Inertia::flash('toast', 'Место добавлено в избранное.');

        return back();
    }

    public function destroy(Request $request, Place $place): RedirectResponse
    {
        $request->user()->favoritePlaces()->detach($place->id);
        $place->deleteIfUnused();

        Inertia::flash('toast', 'Место удалено из избранного.');

        return back();
    }
}
