<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripRequest;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Services\TripReadiness;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TripController extends Controller
{
    /**
     * Trip relations that TripReadiness reads, loaded in advance with one query each.
     */
    protected const ReadinessRelations = ['transports', 'accommodations', 'packingItems', 'days', 'tripPlaces.place.category', 'tripPlaces.day', 'tasks'];

    public function index(Request $request): Response
    {
        $trips = $request->user()->trips()
            ->withCount('tripPlaces')
            ->with(self::ReadinessRelations)
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
     * Trip page, the "Preparation" tab: readiness of the trip by steps and hints on what is missing.
     */
    public function show(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        $trip->load(self::ReadinessRelations);

        return Inertia::render('Trips/Show', [
            'trip' => $trip->toHeader(),
            'description' => $trip->description,
            'readiness' => (new TripReadiness($trip))->toArray(),
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
            'readiness' => (new TripReadiness($trip))->percent(),
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
