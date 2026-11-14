<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Travel time between points from OSRM on the free OpenStreetMap community servers (no key needed).
 * Docs: https://project-osrm.org/docs/v5.24.0/api/#route-service
 */
class RoutingService
{
    public const BaseUrl = 'https://routing.openstreetmap.de';

    /**
     * Profiles of the server: on foot and by car.
     */
    public const Foot = 'foot';

    public const Car = 'car';

    /**
     * How long to wait for the server, in seconds. Free servers sometimes do not answer at all —
     * then the page should not hang.
     */
    public const TimeoutSeconds = 8;

    /**
     * Route through all points in the given order. One request returns every leg A→B, B→C, ...
     * Returns null when the service did not answer or could not build the route.
     *
     * @param  array<int, array{0: float, 1: float}>  $points  [latitude, longitude] of each point
     * @return array<int, array{minutes: int, meters: int}>|null one leg per pair of neighbouring points
     */
    public function legs(string $profile, array $points): ?array
    {
        // OSRM ждёт координаты в порядке «долгота,широта», точки через «;».
        $coordinates = collect($points)
            ->map(fn (array $point) => $point[1].','.$point[0])
            ->implode(';');

        try {
            // В адресе серверов сообщества профиль задаётся папкой (routed-foot / routed-car), а «driving» — общее имя.
            $response = Http::timeout(self::TimeoutSeconds)
                ->withUserAgent('Travelway (student project)')
                ->acceptJson()
                ->get(self::BaseUrl."/routed-{$profile}/route/v1/driving/{$coordinates}", ['overview' => 'false']);
        } catch (ConnectionException) {
            return null;
        }

        $legs = $response->json('routes.0.legs');

        if ($response->failed() || $response->json('code') !== 'Ok' || ! is_array($legs)) {
            return null;
        }

        // duration приходит в секундах, distance — в метрах. Минуты округляем вверх: «0 мин» в пути не бывает.
        return array_map(fn (array $leg) => [
            'minutes' => max(1, (int) ceil($leg['duration'] / 60)),
            'meters' => (int) round($leg['distance']),
        ], $legs);
    }
}
