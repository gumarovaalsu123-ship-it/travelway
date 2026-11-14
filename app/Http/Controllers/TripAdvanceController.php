<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripTaskRequest;
use App\Models\Accommodation;
use App\Models\Transport;
use App\Models\Trip;
use App\Models\TripPlace;
use App\Models\TripTask;
use App\Services\TripReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Заранее" tab of the trip page: what to do before the trip.
 *
 * Automatic to-dos (tickets, bookings) are computed by TripReadiness::advanceTasks();
 * "done" for them is the mark on the record itself (is_booked), so there is only one truth.
 * Own to-dos are stored in trip_tasks. Routes use scoped bindings, so a record of another trip gives 404.
 * After every change we return back(): the same marks are also used on the "Day plan" tab.
 */
class TripAdvanceController extends Controller
{
    public function index(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        // Только то, из чего собираются дела, — по одному запросу на связь.
        $trip->load('transports', 'accommodations', 'tripPlaces.place.category', 'tripPlaces.day', 'tasks');

        $tasks = array_map(fn (array $task) => [
            'kind' => $task['kind'],
            'id' => $task['id'],
            'title' => $task['title'],
            'dueDate' => $task['dueDate']?->toDateString(),
            // Сколько дней осталось до срока: 0 — сегодня, меньше нуля — просрочено.
            'daysLeft' => $task['dueDate'] ? (int) today()->diffInDays($task['dueDate']->copy()->startOfDay(), false) : null,
            'isDone' => $task['isDone'],
            'tab' => $task['tab'],
        ], (new TripReadiness($trip))->advanceTasks());

        return Inertia::render('Trips/Advance', [
            'trip' => $trip->toHeader(),
            'tasks' => $tasks,
        ]);
    }

    /**
     * Add an own to-do, for example "Оформить страховку".
     */
    public function store(TripTaskRequest $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $trip->tasks()->create($request->validated());

        Inertia::flash('toast', 'Дело добавлено.');

        return back();
    }

    /**
     * Change the title or the due date of an own to-do.
     */
    public function update(TripTaskRequest $request, Trip $trip, TripTask $task): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $task->update($request->validated());

        return back();
    }

    /**
     * Tick or untick an own to-do.
     */
    public function toggle(Trip $trip, TripTask $task): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $task->update(['is_done' => ! $task->is_done]);

        return back();
    }

    public function destroy(Trip $trip, TripTask $task): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $task->delete();

        Inertia::flash('toast', 'Дело удалено.');

        return back();
    }

    /**
     * Automatic to-do "Купить билет": tick or untick "билет куплен" on the transport record itself.
     */
    public function toggleTransport(Trip $trip, Transport $transport): RedirectResponse
    {
        Gate::authorize('update', $trip);

        // Для своего авто билет не нужен — такой записи нет среди дел, отметку не ставим.
        abort_unless($transport->needsTicket(), 422);

        $transport->update(['is_booked' => ! $transport->is_booked]);

        return back();
    }

    /**
     * Automatic to-do "Забронировать": tick or untick "забронировано" on the accommodation itself.
     */
    public function toggleAccommodation(Trip $trip, Accommodation $accommodation): RedirectResponse
    {
        Gate::authorize('update', $trip);

        // Жильё «у знакомых» бронировать не нужно.
        abort_unless($accommodation->needsBooking(), 422);

        $accommodation->update(['is_booked' => ! $accommodation->is_booked]);

        return back();
    }

    /**
     * Marks of a place in the plan: "нужен билет/бронь" and "куплено".
     * Used both on the "Day plan" tab and by the automatic to-do on the "Заранее" tab.
     */
    public function updatePlaceBooking(Request $request, Trip $trip, TripPlace $tripPlace): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $marks = $request->validate([
            'needs_booking' => ['sometimes', 'boolean'],
            'is_booked' => ['sometimes', 'boolean'],
        ]);

        $tripPlace->fill($marks);

        // Билет больше не нужен — значит, и «куплено» не о чем.
        if (! $tripPlace->needs_booking) {
            $tripPlace->is_booked = false;
        }

        $tripPlace->save();

        return back();
    }
}
