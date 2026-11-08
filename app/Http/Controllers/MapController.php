<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMapPlaceRequest;
use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MapController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('InteractiveMap', [
            'categories' => PlaceCategory::query()->orderBy('id')->get(['id', 'slug', 'name']),
            'trips' => $user->trips()
                ->with('days')
                ->orderBy('start_date')
                ->get()
                ->map(fn (Trip $trip) => [
                    'id' => $trip->id,
                    'title' => $trip->title,
                    'days' => $trip->days->map(fn (TripDay $day) => [
                        'id' => $day->id,
                        'number' => $day->day_number,
                        'date' => $day->date?->toDateString(),
                    ]),
                ]),
            'places' => $this->placesFor($user),
            'focusPlaceId' => $request->integer('place') ?: null,
        ]);
    }

    public function store(StoreMapPlaceRequest $request): RedirectResponse
    {
        $user = $request->user();

        $place = Place::create([
            ...$request->safe()->only([
                'name', 'category_id', 'address', 'latitude', 'longitude',
                'image', 'description', 'opening_hours', 'website', 'phone', 'wiki_url',
            ]),
            'user_id' => $user->id,
        ]);

        if ($request->validated('destination') === 'favorite') {
            $user->favoritePlaces()->attach($place->id, ['created_at' => now()]);

            Inertia::flash('toast', 'Место добавлено в избранное.');
        } else {
            $day = TripDay::findOrFail($request->validated('trip_day_id'));
            $day->addPlace($place, $request->validated('start_time'));

            Inertia::flash('toast', "Место добавлено в план: день {$day->day_number}.");
        }

        return back();
    }

    /**
     * Places the user has favorited or added to one of their trips.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function placesFor(User $user): array
    {
        $ownTrips = fn ($query) => $query->whereRelation('trip', 'user_id', $user->id);

        return Place::query()
            ->where(fn (Builder $query) => $query
                ->whereHas('tripPlaces', $ownTrips)
                ->orWhereRelation('favoritedBy', 'users.id', $user->id))
            ->with([
                'category',
                'tripPlaces' => fn ($query) => $ownTrips($query)->with(['trip', 'day']),
            ])
            ->withExists(['favoritedBy as is_favorite' => fn (Builder $query) => $query->where('users.id', $user->id)])
            ->get()
            ->map(fn (Place $place) => [
                'id' => $place->id,
                'name' => $place->name,
                'address' => $place->address,
                'lat' => $place->latitude,
                'lng' => $place->longitude,
                'category' => $place->category?->only(['id', 'slug', 'name']),
                ...$place->details(),
                'isFavorite' => (bool) $place->is_favorite,
                'visits' => $place->tripPlaces
                    ->sortBy(fn (TripPlace $visit) => [$visit->trip_id, $visit->day->day_number, $visit->visit_order])
                    ->map(fn (TripPlace $visit) => [
                        'id' => $visit->id,
                        'tripTitle' => $visit->trip->title,
                        'dayNumber' => $visit->day->day_number,
                        'date' => $visit->day->date?->toDateString(),
                        'startTime' => $visit->start_time ? substr($visit->start_time, 0, 5) : null,
                    ])
                    ->values(),
            ])
            ->all();
    }
}
