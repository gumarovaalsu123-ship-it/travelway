<?php

namespace App\Http\Controllers;

use App\Models\Place;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FavoritePlaceController extends Controller
{
    public function index(Request $request): Response
    {
        $places = $request->user()
            ->favoritePlaces()
            ->with('category')
            ->orderByPivot('created_at', 'desc')
            ->get();

        return Inertia::render('Favorites/Index', [
            'places' => $places->map(fn (Place $place) => [
                'id' => $place->id,
                'name' => $place->name,
                'address' => $place->address,
                'lat' => $place->latitude,
                'lng' => $place->longitude,
                'category' => $place->category?->only(['slug', 'name']),
                ...$place->details(),
            ]),
        ]);
    }

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
