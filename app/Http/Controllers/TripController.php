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
     * previousTrip.transports — переезд «Дальше» предыдущего города считается дорогой «Туда».
     */
    protected const ReadinessRelations = ['transports', 'accommodations', 'packingItems', 'days', 'tripPlaces.place.category', 'tripPlaces.day', 'tasks', 'previousTrip.transports'];

    public function index(Request $request): Response
    {
        $trips = $request->user()->trips()
            ->withCount('tripPlaces')
            ->with([...self::ReadinessRelations, 'nextTrip'])
            ->latest()
            ->get();

        return Inertia::render('Trips/Index', [
            'trips' => $trips->map(fn (Trip $trip) => $this->tripCard($trip))->values(),
            'featured' => $this->featuredTrip($trips),
        ]);
    }

    /**
     * Form of a new trip. With ?previous={id} it is the next city of a chain:
     * the city and the start date come from the last "onward" ride of that trip.
     */
    public function create(Request $request): Response
    {
        $previousTrip = $request->filled('previous')
            ? $request->user()->trips()->find($request->integer('previous'))
            : null;

        $onwardRide = $previousTrip?->lastOnwardRide();

        return Inertia::render('Trips/Form', [
            'trip' => null,
            'previousTrip' => $previousTrip ? [
                'id' => $previousTrip->id,
                'title' => $previousTrip->title,
                'destination' => $onwardRide?->arrival_place ?? '',
                // Начало следующей поездки — день прибытия (или отправления, если прибытие не указано).
                'startDate' => ($onwardRide?->arrival_at ?? $onwardRide?->departure_at)?->toDateString(),
            ] : null,
        ]);
    }

    public function store(TripRequest $request): RedirectResponse
    {
        $trip = DB::transaction(function () use ($request) {
            $trip = $request->user()->trips()->create([
                ...$request->tripAttributes(),
                // Предыдущий город задаётся только при создании (см. TripRequest).
                'previous_trip_id' => $request->validated('previous_trip_id'),
            ]);
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
            'previousTrip' => $trip->previousTrip?->only(['id', 'destination']),
            'nextTrip' => $trip->nextTrip?->only(['id', 'destination']),
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
