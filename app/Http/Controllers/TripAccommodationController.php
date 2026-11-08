<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccommodationRequest;
use App\Models\Accommodation;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Accommodation" tab of the trip page: where the traveller stays and which nights are still not covered.
 */
class TripAccommodationController extends Controller
{
    public function index(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        // Сначала жильё с датами по порядку заезда, жильё без дат — в конце.
        $accommodations = $trip->accommodations()
            ->orderByRaw('check_in is null')
            ->orderBy('check_in')
            ->orderBy('id')
            ->get();

        return Inertia::render('Trips/Accommodation', [
            'trip' => $trip->toHeader(),
            'accommodations' => $accommodations->map(fn (Accommodation $accommodation) => [
                'id' => $accommodation->id,
                'name' => $accommodation->name,
                'address' => $accommodation->address,
                // Формат «2026-06-15T15:00» сразу подходит для поля <input type="datetime-local">.
                'checkIn' => $accommodation->check_in?->format('Y-m-d\TH:i'),
                'checkOut' => $accommodation->check_out?->format('Y-m-d\TH:i'),
                'nights' => $accommodation->nights(),
                'bookingInfo' => $accommodation->booking_info,
                'latitude' => $accommodation->latitude,
                'longitude' => $accommodation->longitude,
                'note' => $accommodation->note,
            ])->values(),
            'nights' => $this->nightsCoverage($trip, $accommodations),
        ]);
    }

    public function store(AccommodationRequest $request, Trip $trip): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $accommodation = $trip->accommodations()->create($request->validated());

        Inertia::flash('toast', "Проживание «{$accommodation->name}» добавлено.");

        return to_route('trips.accommodation', $trip);
    }

    /**
     * Thanks to scoped bindings (see routes/web.php) $accommodation always belongs to $trip.
     */
    public function update(AccommodationRequest $request, Trip $trip, Accommodation $accommodation): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $accommodation->update($request->validated());

        Inertia::flash('toast', 'Изменения сохранены.');

        return to_route('trips.accommodation', $trip);
    }

    public function destroy(Trip $trip, Accommodation $accommodation): RedirectResponse
    {
        Gate::authorize('update', $trip);

        $accommodation->delete();

        Inertia::flash('toast', "Проживание «{$accommodation->name}» удалено.");

        return to_route('trips.accommodation', $trip);
    }

    /**
     * How many nights of the trip have a place to stay and which ones do not.
     * A night is named by its evening date: the night of June 15 lasts from June 15 to June 16.
     * Without trip dates the nights cannot be matched with dates, so only the total is known.
     *
     * @param  Collection<int, Accommodation>  $accommodations
     * @return array{total: int, covered: ?int, missing: array<int, array{from: string, to: string}>}
     */
    protected function nightsCoverage(Trip $trip, Collection $accommodations): array
    {
        $total = max($trip->days_count - 1, 0);

        if (! $trip->hasDates()) {
            return ['total' => $total, 'covered' => null, 'missing' => []];
        }

        // Все ночи, на которые есть жильё, в виде набора дат «Y-m-d».
        $coveredDates = [];

        foreach ($accommodations as $accommodation) {
            if ($accommodation->nights() === null) {
                continue;
            }

            for ($night = 0; $night < $accommodation->nights(); $night++) {
                $coveredDates[$accommodation->check_in->copy()->addDays($night)->toDateString()] = true;
            }
        }

        $covered = 0;
        $missing = [];
        $start = CarbonImmutable::parse($trip->start_date);

        for ($night = 0; $night < $total; $night++) {
            $date = $start->addDays($night);

            if (isset($coveredDates[$date->toDateString()])) {
                $covered++;

                continue;
            }

            // Соседние ночи без жилья объединяем в один промежуток «с — по».
            $last = array_key_last($missing);

            if ($last !== null && $missing[$last]['to'] === $date->toDateString()) {
                $missing[$last]['to'] = $date->addDay()->toDateString();
            } else {
                $missing[] = ['from' => $date->toDateString(), 'to' => $date->addDay()->toDateString()];
            }
        }

        return ['total' => $total, 'covered' => $covered, 'missing' => $missing];
    }
}
