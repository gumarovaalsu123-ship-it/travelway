<?php

namespace App\Http\Controllers;

use App\Models\Accommodation;
use App\Models\Transport;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripPlace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public read-only page of a trip by its secret link /t/{token} — no login needed.
 * A guest and any logged-in user (the owner too) see exactly the same.
 *
 * Отдаём только то, что нужно для просмотра маршрута. Личное не уходит в браузер совсем
 * (а не просто прячется на странице): название и описание поездки, заметки к местам, отметки и данные броней,
 * заметки к билетам, адрес и координаты жилья, имя «у знакомых», почта автора.
 * Автора показываем только по имени и аватару — чтобы было видно, чей это маршрут.
 */
class PublicTripController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $trip = Trip::query()->where('public_token', $token)->first();

        // Неверный токен или доступ закрыт (токен стёрт) — одна и та же страница, по ней не понять, была ли поездка.
        if ($trip === null) {
            return $this->noindex(
                Inertia::render('Trips/PublicUnavailable')->toResponse($request)->setStatusCode(404)
            );
        }

        $trip->load('user', 'days.tripPlaces.place.category', 'transports', 'accommodations');

        return $this->noindex(Inertia::render('Trips/Public', [
            'author' => [
                'name' => $trip->user->name,
                'avatarUrl' => $trip->user->avatarUrl(),
                'initials' => $trip->user->initials(),
            ],
            'trip' => [
                'destination' => $trip->destination,
                'coverImage' => $trip->cover_image,
                'startDate' => $trip->start_date?->toDateString(),
                'endDate' => $trip->end_date?->toDateString(),
                'daysCount' => $trip->days_count,
                'placesCount' => $trip->tripPlaces->count(),
            ],
            'days' => $trip->days->map(fn (TripDay $day) => [
                'number' => $day->day_number,
                'date' => $day->date?->toDateString(),
                'title' => $day->title,
                // Компонент карты дня (DayMap) ждёт эти поля. Жильё на карту не ставим — это адрес, где живёт человек.
                'morningLodging' => null,
                'eveningLodging' => null,
                'returnMode' => null,
                'places' => $day->tripPlaces->map(fn (TripPlace $visit, int $index) => [
                    'id' => $visit->id,
                    'name' => $visit->place->name,
                    'address' => $visit->place->address,
                    // Описание самого места (из Википедии/OSM), а не личная заметка путешественника.
                    'category' => $visit->place->category?->name,
                    'image' => $visit->place->image,
                    'lat' => $visit->place->latitude,
                    'lng' => $visit->place->longitude,
                    'startTime' => $visit->start_time ? substr($visit->start_time, 0, 5) : null,
                    'endTime' => $visit->end_time ? substr($visit->end_time, 0, 5) : null,
                    'stayMinutes' => $visit->stayMinutes(),
                    // Способ добраться — по нему карта рисует отрезок пешком или по дорогам.
                    'mode' => $visit->calculated_mode ?? $visit->travel_mode,
                    // Дорога от предыдущего места (как на вкладке «План»). У первого места отрезок идёт от жилья — его не показываем.
                    'segment' => $index > 0 && $visit->travel_minutes !== null ? [
                        'mode' => $visit->calculated_mode,
                        'minutes' => $visit->travel_minutes,
                        'approximate' => $visit->calculated_mode === 'transit',
                    ] : null,
                ])->values(),
            ])->values(),
            'transports' => $trip->transports
                ->sortBy(fn (Transport $ride) => $ride->departure_at?->timestamp ?? 0)
                ->map(fn (Transport $ride) => [
                    'type' => $ride->type,
                    'direction' => $ride->direction,
                    'departurePlace' => $ride->departure_place,
                    'arrivalPlace' => $ride->arrival_place,
                    'departureAt' => $ride->departure_at?->format('Y-m-d\TH:i'),
                    'arrivalAt' => $ride->arrival_at?->format('Y-m-d\TH:i'),
                ])->values(),
            'accommodations' => $trip->accommodations
                ->sortBy(fn (Accommodation $lodging) => $lodging->check_in?->timestamp ?? 0)
                ->map(fn (Accommodation $lodging) => [
                    'type' => $lodging->type,
                    // «У знакомых» обычно назван по имени человека — его не показываем.
                    'name' => $lodging->type === 'friends' ? null : $lodging->name,
                    'checkIn' => $lodging->check_in?->format('Y-m-d\TH:i'),
                    'checkOut' => $lodging->check_out?->format('Y-m-d\TH:i'),
                    'nights' => $lodging->nights(),
                ])->values(),
        ])->toResponse($request));
    }

    /**
     * Просим поисковики не индексировать страницу: ссылка секретная, её знают только те, кому её отправили.
     * Заголовок работает и без JavaScript; такой же <meta name="robots"> страница добавляет в head.
     */
    private function noindex(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
