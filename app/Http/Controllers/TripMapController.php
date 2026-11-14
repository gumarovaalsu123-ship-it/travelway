<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use App\Services\DaySchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Map" tab of the trip page: the stops of one chosen day on the map, numbered as in the day plan.
 * The route line itself is drawn on the page (OSRM geometry, see Pages/Trips/Map.jsx).
 */
class TripMapController extends Controller
{
    public function index(Request $request, Trip $trip, DaySchedule $schedule): Response
    {
        Gate::authorize('view', $trip);

        $days = $trip->days()->withCount('tripPlaces')->get();

        // «Показать на карте» на вкладке «Проживание» ведёт сюда с ?lodging=id: это жильё подсвечиваем.
        // Ищем только среди жилья этой поездки и только с координатами — иначе показать нечего.
        $focusLodging = $request->filled('lodging')
            ? $trip->accommodations()->whereKey($request->integer('lodging'))->whereNotNull('latitude')->first()
            : null;

        // Без ?day= при переходе к жилью открываем день заезда в него.
        $dayNumber = $request->integer('day') ?: $this->checkInDayNumber($trip, $focusLodging);
        $selectedDay = $trip->dayByNumber($days, $dayNumber);

        $selectedDay?->load('tripPlaces.place.category', 'trip.accommodations', 'trip.transports');
        $summary = $selectedDay ? $schedule->summary($selectedDay) : null;

        return Inertia::render('Trips/Map', [
            'trip' => $trip->toHeader(),
            'days' => $days->map(fn (TripDay $day) => [
                'id' => $day->id,
                'number' => $day->day_number,
                'date' => $day->date?->toDateString(),
                'placesCount' => $day->trip_places_count,
            ])->values(),
            'selectedDay' => $selectedDay ? [
                'id' => $selectedDay->id,
                'number' => $selectedDay->day_number,
                'date' => $selectedDay->date?->toDateString(),
                'title' => $selectedDay->title,
                'startTime' => $summary['startTime'],
                'endTime' => $summary['endTime'],
                'isCalculated' => $summary['isCalculated'],
                'travelMinutes' => $summary['travelMinutes'],
                // Жильё — как в расписании: откуда день начинается и куда возвращаемся вечером (может не быть).
                'morningLodging' => $this->lodging($schedule->morningLodging($selectedDay)),
                'eveningLodging' => $this->lodging($schedule->eveningLodging($selectedDay)),
                'returnMode' => $selectedDay->return_mode,
                // Места уже идут по visit_order (так задана связь tripPlaces), поэтому номера совпадают с планом.
                'places' => $selectedDay->tripPlaces->values()->map(fn (TripPlace $visit, int $index) => [
                    'id' => $visit->id,
                    'number' => $index + 1,
                    'name' => $visit->place->name,
                    'address' => $visit->place->address,
                    'category' => $visit->place->category?->name,
                    'lat' => $visit->place->latitude,
                    'lng' => $visit->place->longitude,
                    'startTime' => $visit->start_time ? substr($visit->start_time, 0, 5) : null,
                    'endTime' => $visit->end_time ? substr($visit->end_time, 0, 5) : null,
                    // Способ на отрезке к этому месту: рассчитанный, иначе выбранный человеком, иначе неизвестен.
                    'mode' => $visit->calculated_mode ?? $visit->travel_mode,
                    'travelMinutes' => $visit->travel_minutes,
                ]),
            ] : null,
            'focusLodging' => $this->lodging($focusLodging),
        ]);
    }

    /**
     * Номер дня поездки, в который заезжаем в жильё (заезд накануне начала — день 1). 0 — день неизвестен.
     */
    private function checkInDayNumber(Trip $trip, ?Accommodation $lodging): int
    {
        if ($lodging?->check_in === null || ! $trip->hasDates()) {
            return 0;
        }

        $daysFromStart = (int) $trip->start_date->diffInDays($lodging->check_in->copy()->startOfDay(), false);

        return max($daysFromStart, 0) + 1;
    }

    /**
     * @return array{id: int, name: string, address: ?string, lat: float, lng: float}|null
     */
    private function lodging(?Accommodation $lodging): ?array
    {
        if ($lodging === null) {
            return null;
        }

        return [
            'id' => $lodging->id,
            'name' => $lodging->name,
            'address' => $lodging->address,
            'lat' => $lodging->latitude,
            'lng' => $lodging->longitude,
        ];
    }
}
