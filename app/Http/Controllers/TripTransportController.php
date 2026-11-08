<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransportRequest;
use App\Models\Transport;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Transport" tab of the trip page: flights, trains and other trip legs.
 * The {transport} route parameter is scoped to {trip} (see routes/web.php),
 * so a record of someone else's trip cannot be reached through your own trip.
 */
class TripTransportController extends Controller
{
    public function index(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        $transports = $trip->transports()
            // Сначала записи со временем по порядку, записи без времени — в конце списка.
            ->orderByRaw('departure_at is null')
            ->orderBy('departure_at')
            ->orderBy('id')
            ->get();

        return Inertia::render('Trips/Transport', [
            'trip' => $trip->toHeader(),
            'transports' => $transports->map(fn (Transport $transport) => [
                'id' => $transport->id,
                'type' => $transport->type,
                'departurePlace' => $transport->departure_place,
                'arrivalPlace' => $transport->arrival_place,
                // Формат «2026-06-15T13:15» понимает поле <input type="datetime-local">.
                'departureAt' => $transport->departure_at?->format('Y-m-d\TH:i'),
                'arrivalAt' => $transport->arrival_at?->format('Y-m-d\TH:i'),
                'bookingInfo' => $transport->booking_info,
                'note' => $transport->note,
            ])->values(),
            'types' => Transport::Types,
        ]);
    }

    public function store(TransportRequest $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $trip->transports()->create($request->validated());

        Inertia::flash('toast', 'Запись о транспорте добавлена.');

        return to_route('trips.transport', $trip);
    }

    public function update(TransportRequest $request, Trip $trip, Transport $transport): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $transport->update($request->validated());

        Inertia::flash('toast', 'Изменения сохранены.');

        return to_route('trips.transport', $trip);
    }

    public function destroy(Trip $trip, Transport $transport): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $transport->delete();

        Inertia::flash('toast', 'Запись о транспорте удалена.');

        return to_route('trips.transport', $trip);
    }
}
