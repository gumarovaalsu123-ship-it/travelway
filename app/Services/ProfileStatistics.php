<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Статистика для страницы профиля.
 *
 * Статус поездки не хранится в базе, а вычисляется по датам (Trip::status), поэтому поездки
 * загружаются одним запросом и считаются уже в PHP. Поездок у одного человека немного — это нормально.
 *
 * Что как считается:
 *  - завершённые — даты уже прошли; запланированные — ещё впереди (с датами или черновик без дат);
 *  - дней в пути — все дни завершённых поездок плюс уже прошедшие дни текущей;
 *  - мест посещено — места из завершённых поездок, запланировано — из остальных;
 *  - городов — разных городов (destination) среди завершённых и текущей поездок.
 */
class ProfileStatistics
{
    /**
     * @return array{
     *     trips: array{total: int, completed: int, planned: int, current: int},
     *     daysTraveled: int,
     *     places: array{visited: int, planned: int},
     *     cities: int,
     *     favorites: int,
     *     nextTrip: ?array{id: int, title: string, destination: string, startDate: ?string, endDate: ?string, status: string, daysLeft: ?int},
     * }
     */
    public function for(User $user): array
    {
        $trips = $user->trips()->withCount('tripPlaces')->get();

        $completed = $trips->filter(fn (Trip $trip) => $trip->status === Trip::StatusCompleted);
        $current = $trips->filter(fn (Trip $trip) => $trip->status === Trip::StatusCurrent);
        $upcoming = $trips->filter(fn (Trip $trip) => in_array($trip->status, [Trip::StatusPlanned, Trip::StatusDraft], true));

        return [
            'trips' => [
                'total' => $trips->count(),
                'completed' => $completed->count(),
                'planned' => $upcoming->count(),
                'current' => $current->count(),
            ],
            'daysTraveled' => $completed->sum('days_count') + $current->sum(
                // Текущая поездка: сегодняшний день тоже считается.
                fn (Trip $trip) => (int) $trip->start_date->diffInDays(today()) + 1
            ),
            'places' => [
                'visited' => $completed->sum('trip_places_count'),
                'planned' => $upcoming->sum('trip_places_count') + $current->sum('trip_places_count'),
            ],
            'cities' => $this->countCities($completed->merge($current)),
            'favorites' => $user->favoritePlaces()->count(),
            'nextTrip' => $this->nextTrip($current, $upcoming),
        ];
    }

    /**
     * Разные города без учёта регистра и пробелов по краям: «Казань» и « казань» — один город.
     *
     * @param  Collection<int, Trip>  $trips
     */
    private function countCities(Collection $trips): int
    {
        return $trips
            ->map(fn (Trip $trip) => mb_strtolower(trim($trip->destination)))
            ->unique()
            ->count();
    }

    /**
     * Ближайшая поездка: если человек сейчас в пути — текущая, иначе запланированная с самой ранней датой.
     * Черновики без дат не показываем: у них нет «ближайшей» даты.
     *
     * @param  Collection<int, Trip>  $current
     * @param  Collection<int, Trip>  $upcoming
     * @return ?array{id: int, title: string, destination: string, startDate: ?string, endDate: ?string, status: string, daysLeft: ?int}
     */
    private function nextTrip(Collection $current, Collection $upcoming): ?array
    {
        $trip = $current->first()
            ?? $upcoming->filter(fn (Trip $trip) => $trip->hasDates())->sortBy('start_date')->first();

        if ($trip === null) {
            return null;
        }

        return [
            'id' => $trip->id,
            'title' => $trip->title,
            'destination' => $trip->destination,
            'startDate' => $trip->start_date?->toDateString(),
            'endDate' => $trip->end_date?->toDateString(),
            'status' => $trip->status,
            // Сколько дней осталось до начала (для текущей поездки — null).
            'daysLeft' => $trip->status === Trip::StatusPlanned ? (int) today()->diffInDays($trip->start_date) : null,
        ];
    }
}
