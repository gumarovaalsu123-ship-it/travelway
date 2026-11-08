<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripRequest;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TripController extends Controller
{
    public function index(Request $request): Response
    {
        $trips = $request->user()->trips()
            ->withCount('tripPlaces')
            ->withCount(['days as planned_days_count' => fn ($query) => $query->has('tripPlaces')])
            ->latest()
            ->get();

        return Inertia::render('Trips/Index', [
            'trips' => $trips->map(fn (Trip $trip) => $this->tripCard($trip))->values(),
            'featured' => $this->featuredTrip($trips),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Trips/Form', ['trip' => null]);
    }

    public function store(TripRequest $request): RedirectResponse
    {
        $trip = DB::transaction(function () use ($request) {
            $trip = $request->user()->trips()->create($request->tripAttributes());
            $trip->syncDays();

            return $trip;
        });

        Inertia::flash('toast', "Путешествие «{$trip->title}» создано. Добавьте в него места на карте.");

        return to_route('map.index');
    }

    /**
     * Trip page, the "Overview" tab: every day of the trip with the places planned on it.
     */
    public function show(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        $trip->load('days.tripPlaces.place.category');

        return Inertia::render('Trips/Show', [
            'trip' => $trip->toHeader(),
            'description' => $trip->description,
            'days' => $trip->days->map(fn (TripDay $day) => [
                'id' => $day->id,
                'number' => $day->day_number,
                'date' => $day->date?->toDateString(),
                'title' => $day->title,
                'places' => $day->tripPlaces->map(fn (TripPlace $visit) => [
                    'id' => $visit->id,
                    'name' => $visit->place->name,
                    'address' => $visit->place->address,
                    'category' => $visit->place->category?->name,
                    'categorySlug' => $visit->place->category?->slug,
                    ...$visit->place->details(),
                    // В базе время хранится как «10:00:00», показываем «10:00».
                    'startTime' => $visit->start_time ? substr($visit->start_time, 0, 5) : null,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Placeholder for a trip tab that is not built yet (see the tab routes in routes/web.php).
     */
    public function comingSoon(Trip $trip, string $tab): Response
    {
        Gate::authorize('view', $trip);

        return Inertia::render('Trips/ComingSoon', [
            'trip' => $trip->toHeader(),
            'tab' => $tab,
        ]);
    }

    public function edit(Trip $trip): Response
    {
        Gate::authorize('update', $trip);

        return Inertia::render('Trips/Form', [
            'trip' => [
                'id' => $trip->id,
                'title' => $trip->title,
                'destination' => $trip->destination,
                'description' => $trip->description,
                'startDate' => $trip->start_date?->toDateString(),
                'endDate' => $trip->end_date?->toDateString(),
                'daysCount' => $trip->days_count,
                'isPublic' => $trip->is_public,
                'lastPlannedDay' => (int) $trip->days()->has('tripPlaces')->max('day_number'),
            ],
        ]);
    }

    public function update(TripRequest $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        DB::transaction(function () use ($request, $trip) {
            $placeIds = $trip->tripPlaces()->pluck('place_id');

            $trip->update($request->tripAttributes());
            $trip->syncDays();

            $this->deleteUnusedPlaces($placeIds->all());
        });

        Inertia::flash('toast', 'Изменения сохранены.');

        return to_route('trips.index');
    }

    public function destroy(Trip $trip): RedirectResponse
    {
        Gate::authorize('delete', $trip);

        DB::transaction(function () use ($trip) {
            $placeIds = $trip->tripPlaces()->pluck('place_id');

            $trip->delete();

            $this->deleteUnusedPlaces($placeIds->all());
        });

        Inertia::flash('toast', "Путешествие «{$trip->title}» удалено.");

        return to_route('trips.index');
    }

    /**
     * @return array<string, mixed>
     */
    protected function tripCard(Trip $trip): array
    {
        return [
            'id' => $trip->id,
            'title' => $trip->title,
            'destination' => $trip->destination,
            'startDate' => $trip->start_date?->toDateString(),
            'endDate' => $trip->end_date?->toDateString(),
            'daysCount' => $trip->days_count,
            'status' => $trip->status,
            'isPublic' => $trip->is_public,
            'placesCount' => $trip->trip_places_count,
            'progress' => (int) round($trip->planned_days_count / max($trip->days_count, 1) * 100),
        ];
    }

    /**
     * The trip that is happening now, otherwise the nearest upcoming one, with the day to focus on.
     *
     * @param  Collection<int, Trip>  $trips
     * @return array<string, mixed>|null
     */
    protected function featuredTrip(Collection $trips): ?array
    {
        $trip = $trips->firstWhere('status', Trip::StatusCurrent)
            ?? $trips->where('status', Trip::StatusPlanned)->sortBy('start_date')->first();

        if (! $trip) {
            return null;
        }

        $dayNumber = $trip->status === Trip::StatusCurrent
            ? (int) $trip->start_date->diffInDays(today()) + 1
            : 1;

        /** @var TripDay|null $day */
        $day = $trip->days()->where('day_number', $dayNumber)->with('tripPlaces.place')->first();

        return [
            'id' => $trip->id,
            'isCurrent' => $trip->status === Trip::StatusCurrent,
            'dayNumber' => $dayNumber,
            'dayTitle' => $day?->title,
            'placeNames' => $day?->tripPlaces->map(fn (TripPlace $visit) => $visit->place->name)->values() ?? [],
        ];
    }

    /**
     * @param  array<int, int>  $placeIds
     */
    protected function deleteUnusedPlaces(array $placeIds): void
    {
        Place::query()->whereKey(array_unique($placeIds))->get()->each->deleteIfUnused();
    }
}
