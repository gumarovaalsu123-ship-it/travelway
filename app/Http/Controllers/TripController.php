<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripRequest;
use App\Models\PackingList;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Services\DaySchedule;
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
            // Стандартный набор вещей кладём сразу: лишнее человек уберёт сам на вкладке «Вещи».
            PackingList::addDefaultsTo($trip);

            return $trip;
        });

        // Обложку ищем уже после сохранения: если Википедия не ответит, поездка всё равно создана.
        $trip->refreshCover();

        Inertia::flash('toast', "Путешествие «{$trip->title}» создано.");

        // Кнопка «Создать и добавить места» — сразу во вкладку «План», где места добавляются на карте дня.
        return to_route('trips.plan', $trip);
    }

    /**
     * The first tab of the trip page: "Preparation" (readiness by steps and hints on what is missing),
     * and for a trip that is happening now — "Today", the overview of the current day.
     */
    public function show(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        if ($trip->hasTab('today')) {
            return $this->today($trip);
        }

        $trip->load(self::ReadinessRelations);

        return Inertia::render('Trips/Show', [
            'trip' => $trip->toHeader(),
            'description' => $trip->description,
            'readiness' => (new TripReadiness($trip))->toArray(),
        ]);
    }

    /**
     * The "Settings" tab: the trip form inside the trip page. The header data (Trip::toHeader(),
     * as on the other tabs) and the form fields come together in one `trip` prop.
     */
    public function edit(Trip $trip): Response
    {
        Gate::authorize('updateSettings', $trip);

        return Inertia::render('Trips/Form', [
            'trip' => [
                ...$trip->toHeader(),
                'description' => $trip->description,
                'isPublic' => $trip->is_public,
                // Публичная ссылка для кнопки «Скопировать ссылку»; null — доступ по ссылке закрыт.
                'publicUrl' => $trip->public_token ? route('trips.public', $trip->public_token) : null,
                'lastPlannedDay' => (int) $trip->days()->has('tripPlaces')->max('day_number'),
                'isCompleted' => $trip->status === Trip::StatusCompleted,
            ],
        ]);
    }

    public function update(TripRequest $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('updateSettings', $trip);

        $oldDestination = $trip->destination;

        DB::transaction(function () use ($request, $trip) {
            $placeIds = $trip->tripPlaces()->pluck('place_id');

            $trip->update($request->tripAttributes());
            $trip->syncDays();

            $this->deleteUnusedPlaces($placeIds->all());
        });

        // Город поменялся — нужна и новая обложка.
        if ($trip->destination !== $oldDestination) {
            $trip->refreshCover();
        }

        Inertia::flash('toast', 'Изменения сохранены.');

        // Остаёмся во вкладке «Настройки» этой же поездки — тост покажется там.
        return to_route('trips.edit', $trip);
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
            'coverImage' => $trip->cover_image,
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
        $day = $trip->days->firstWhere('day_number', $dayNumber);

        return [
            'id' => $trip->id,
            'isCurrent' => $trip->status === Trip::StatusCurrent,
            'dayNumber' => $dayNumber,
            'dayTitle' => $day?->title,
        ];
    }

    /**
     * The "Today" tab of a trip that is happening now: today's places in order, which one is next
     * (the first one that has not ended yet by the planned time) and the lodging to return to.
     */
    protected function today(Trip $trip): Response
    {
        $days = $trip->days()->with('tripPlaces.place.category')->get();
        // Номера 0 нет, поэтому dayByNumber() откроет сегодняшний день текущей поездки.
        $day = $trip->dayByNumber($days, 0);
        $now = now()->format('H:i');

        $places = $day?->tripPlaces->map(fn (TripPlace $visit) => [
            'id' => $visit->id,
            'name' => $visit->place->name,
            'address' => $visit->place->address,
            'category' => $visit->place->category?->name,
            'image' => $visit->place->image,
            'startTime' => $visit->start_time ? substr($visit->start_time, 0, 5) : null,
            'endTime' => $visit->end_time ? substr($visit->end_time, 0, 5) : null,
            'note' => $visit->note,
        ])->values() ?? collect();

        // «Что дальше»: первое место, которое по плану ещё не закончилось. Время «10:00» можно сравнивать строками.
        // У мест без времени не понять, прошли они или нет, — тогда следующим считаем первое место дня.
        $next = $places->first(fn (array $place) => ($place['endTime'] ?? $place['startTime']) === null
            || ($place['endTime'] ?? $place['startTime']) >= $now);

        return Inertia::render('Trips/Today', [
            'trip' => $trip->toHeader(),
            'day' => $day ? [
                'id' => $day->id,
                'number' => $day->day_number,
                'date' => $day->date?->toDateString(),
                'title' => $day->title,
            ] : null,
            'places' => $places,
            'nextPlaceId' => $next['id'] ?? null,
            // Куда возвращаться вечером — то же жильё, что считает расписание дня.
            'lodging' => $day ? app(DaySchedule::class)->eveningLodging($day)?->only(['name', 'address']) : null,
        ]);
    }

    /**
     * @param  array<int, int>  $placeIds
     */
    protected function deleteUnusedPlaces(array $placeIds): void
    {
        Place::query()->whereKey(array_unique($placeIds))->get()->each->deleteIfUnused();
    }
}
