<?php

namespace App\Services;

use App\Models\Accommodation;
use App\Models\Transport;
use App\Models\TripDay;
use App\Models\TripPlace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Schedule of one day: the "Рассчитать время" button and the hints on the "Day plan" tab (concept, section 3.5).
 *
 * The day is a chain of points: [lodging in the morning] → place 1 → place 2 → ... → [lodging in the evening].
 * A segment between two points is stored at the place it leads to (trip_places.travel_minutes),
 * the last segment back to the lodging — at the day (trip_days.return_minutes).
 */
class DaySchedule
{
    /**
     * The day starts at 10:00 unless the traveller chose another time.
     */
    public const DefaultStartTime = '10:00';

    /**
     * Up to this many minutes on foot the calculation chooses to walk.
     */
    public const MaxWalkMinutes = 20;

    /**
     * Public transport is not worth it for short distances: up to 1.5 km we walk.
     */
    public const TransitWalkMeters = 1500;

    /**
     * Last day: warn when less than an hour is left before the departure home or onward.
     */
    public const DepartureBufferMinutes = 60;

    /**
     * Latest time that fits into a day: the time column cannot hold "24:30".
     */
    private const LastMinuteOfDay = 23 * 60 + 59;

    public function __construct(private RoutingService $routing) {}

    /**
     * Calculate the travel times and the visit times of the day and save them.
     * Returns false when the routing service did not answer — then nothing is changed.
     */
    public function calculate(TripDay $day): bool
    {
        $day->loadMissing('tripPlaces.place.category', 'trip.accommodations', 'trip.transports');
        $visits = $day->tripPlaces;

        if ($visits->isEmpty()) {
            return true;
        }

        $morningLodging = $this->morningLodging($day);
        $eveningLodging = $this->eveningLodging($day);

        // Все точки дня по порядку и способ, выбранный человеком, для каждого отрезка между ними.
        $points = [];
        $chosenModes = [];

        if ($morningLodging) {
            $points[] = [$morningLodging->latitude, $morningLodging->longitude];
        }

        foreach ($visits as $index => $visit) {
            $points[] = [$visit->place->latitude, $visit->place->longitude];

            // У первого места без жилья утром входящего отрезка нет.
            if ($morningLodging || $index > 0) {
                $chosenModes[] = $visit->travel_mode;
            }
        }

        if ($eveningLodging) {
            $points[] = [$eveningLodging->latitude, $eveningLodging->longitude];
            $chosenModes[] = null; // дорогу к жилью всегда выбирает расчёт
        }

        $segments = [];

        if (count($points) > 1) {
            $segments = $this->segments($points, $chosenModes, $this->hasOwnCar($day));

            if ($segments === null) {
                return false;
            }
        }

        // Транзакция: расписание дня сохраняется целиком или не сохраняется вовсе.
        DB::transaction(function () use ($day, $visits, $segments, $morningLodging, $eveningLodging) {
            $clock = self::toMinutes($this->startTime($day));
            $segmentIndex = 0;

            foreach ($visits as $index => $visit) {
                $segment = ($morningLodging || $index > 0) ? $segments[$segmentIndex++] : null;
                $arrival = $clock + ($segment['minutes'] ?? 0);

                $changes = [
                    'travel_minutes' => $segment['minutes'] ?? null,
                    'calculated_mode' => $segment['mode'] ?? null,
                ];

                if ($visit->time_locked && $visit->start_time) {
                    // Закреплённое время не двигаем: следующее место считаем от его окончания.
                    $start = self::toMinutes($visit->start_time);
                    $end = $visit->end_time ? self::toMinutes($visit->end_time) : $start + $visit->stayMinutes();
                } else {
                    $start = $arrival;
                    $end = $arrival + $visit->stayMinutes();
                    $changes['start_time'] = self::formatTime($start);
                    $changes['end_time'] = self::formatTime($end);
                }

                $visit->update($changes);
                $clock = $end;
            }

            $returnSegment = $eveningLodging ? $segments[$segmentIndex] : null;

            $day->update([
                'return_minutes' => $returnSegment['minutes'] ?? null,
                'return_mode' => $returnSegment['mode'] ?? null,
            ]);
        });

        return true;
    }

    /**
     * Everything the "Day plan" tab shows about time: segments, hints and the total of the day.
     * Built only from saved data, the routing service is not asked.
     *
     * @return array{
     *     startTime: string,
     *     isCalculated: bool,
     *     places: array<int, array{segment: ?array{mode: ?string, minutes: int, approximate: bool, yandexUrl: ?string}, lateMinutes: ?int, freeMinutes: ?int}>,
     *     returnSegment: ?array{mode: ?string, minutes: int, approximate: bool, lodging: string},
     *     endTime: ?string,
     *     travelMinutes: int,
     *     stayMinutes: int,
     *     warnings: array<int, string>,
     * }
     */
    public function summary(TripDay $day): array
    {
        $day->loadMissing('tripPlaces.place.category', 'trip.accommodations', 'trip.transports');
        $visits = $day->tripPlaces;
        $startTime = $this->startTime($day);
        $morningLodging = $this->morningLodging($day);
        $eveningLodging = $this->eveningLodging($day);

        // Расписание считается рассчитанным, если хоть один отрезок есть в базе.
        $isCalculated = $day->return_minutes !== null || $visits->contains(fn (TripPlace $visit) => $visit->travel_minutes !== null);

        $places = [];
        $travelMinutes = (int) $day->return_minutes;
        $previousEnd = self::toMinutes($startTime);
        $previousPoint = $morningLodging ? [$morningLodging->latitude, $morningLodging->longitude] : null;

        foreach ($visits as $visit) {
            $point = [$visit->place->latitude, $visit->place->longitude];
            $segment = null;
            $lateMinutes = null;
            $freeMinutes = null;

            if ($visit->travel_minutes !== null) {
                $travelMinutes += $visit->travel_minutes;
                $segment = [
                    'mode' => $visit->calculated_mode,
                    'minutes' => $visit->travel_minutes,
                    // Время на общественном транспорте — только оценка, поэтому «≈» и ссылка на точный маршрут.
                    'approximate' => $visit->calculated_mode === 'transit',
                    'yandexUrl' => $previousPoint ? self::yandexRouteUrl([$previousPoint, $point], $visit->calculated_mode) : null,
                ];
            }

            // Успеваем ли к началу места: приезд = конец предыдущего (или начало дня) + дорога.
            if ($isCalculated && $visit->start_time && $previousEnd !== null) {
                $difference = self::toMinutes($visit->start_time) - ($previousEnd + (int) $visit->travel_minutes);
                $lateMinutes = $difference < 0 ? -$difference : null;
                $freeMinutes = $difference > 0 ? $difference : null;
            }

            $places[$visit->id] = ['segment' => $segment, 'lateMinutes' => $lateMinutes, 'freeMinutes' => $freeMinutes];
            $previousEnd = $visit->end_time ? self::toMinutes($visit->end_time) : null;
            $previousPoint = $point;
        }

        $returnSegment = null;

        if ($day->return_minutes !== null && $eveningLodging) {
            $returnSegment = [
                'mode' => $day->return_mode,
                'minutes' => $day->return_minutes,
                'approximate' => $day->return_mode === 'transit',
                'lodging' => $eveningLodging->name,
            ];
        }

        // Конец дня: окончание последнего места + дорога к жилью.
        $endMinutes = $previousEnd !== null && $visits->isNotEmpty() ? $previousEnd + (int) $day->return_minutes : null;

        return [
            'startTime' => $startTime,
            'isCalculated' => $isCalculated,
            'places' => $places,
            'returnSegment' => $returnSegment,
            'endTime' => $endMinutes !== null ? self::formatTime($endMinutes) : null,
            'travelMinutes' => $travelMinutes,
            'stayMinutes' => $visits->sum(fn (TripPlace $visit) => $visit->stayMinutes()),
            'warnings' => $endMinutes !== null ? $this->departureWarnings($day, $endMinutes) : [],
        ];
    }

    /**
     * Start of the day: the time the traveller chose; on the arrival day — not earlier than the arrival "Туда";
     * otherwise 10:00.
     */
    public function startTime(TripDay $day): string
    {
        if ($day->start_time) {
            return substr($day->start_time, 0, 5);
        }

        $arrival = $day->date ? $day->trip->transports
            ->where('direction', 'to')
            ->filter(fn (Transport $transport) => $transport->arrival_at?->isSameDay($day->date))
            ->max(fn (Transport $transport) => $transport->arrival_at->format('H:i')) : null;

        // «ЧЧ:ММ» можно сравнивать как строки: «14:20» > «10:00».
        return $arrival !== null && $arrival > self::DefaultStartTime ? $arrival : self::DefaultStartTime;
    }

    /**
     * Way to get along each segment and the minutes on the way.
     * At most two requests: on foot for all segments, by car — only if some segment needs it.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @param  array<int, ?string>  $chosenModes  mode chosen by the traveller for each segment, null — automatic
     * @return array<int, array{mode: string, minutes: int}>|null null when the routing service did not answer
     */
    private function segments(array $points, array $chosenModes, bool $hasOwnCar): ?array
    {
        $walkLegs = $this->routing->legs(RoutingService::Foot, $points);

        if ($walkLegs === null || count($walkLegs) !== count($chosenModes)) {
            return null;
        }

        $modes = [];
        $needsCar = false;

        foreach ($walkLegs as $index => $walk) {
            $modes[$index] = $chosenModes[$index] ?? self::automaticMode($walk['minutes'], $hasOwnCar);
            $needsCar = $needsCar || self::modeNeedsCarTime($modes[$index], $walk['meters']);
        }

        $carLegs = $needsCar ? $this->routing->legs(RoutingService::Car, $points) : [];

        if ($carLegs === null || ($needsCar && count($carLegs) !== count($walkLegs))) {
            return null;
        }

        $segments = [];

        foreach ($walkLegs as $index => $walk) {
            $segments[] = self::segment($modes[$index], $walk, $carLegs[$index] ?? null);
        }

        return $segments;
    }

    /**
     * Automatic choice (concept, section 3.5): up to 20 minutes on foot — walk,
     * otherwise by car if the traveller came in their own car, else by public transport.
     */
    public static function automaticMode(int $walkMinutes, bool $hasOwnCar): string
    {
        if ($walkMinutes <= self::MaxWalkMinutes) {
            return 'walk';
        }

        return $hasOwnCar ? 'car' : 'transit';
    }

    /**
     * Minutes on the way for the chosen mode.
     * Public transport has no free source of routes and timetables in Russia, so it is an estimate:
     * up to 1.5 km — on foot, further — car time × 1.5 + 10 minutes for walking to the stop and waiting.
     *
     * @param  array{minutes: int, meters: int}  $walk
     * @param  array{minutes: int, meters: int}|null  $car
     * @return array{mode: string, minutes: int}
     */
    public static function segment(string $mode, array $walk, ?array $car): array
    {
        return match (true) {
            $mode === 'car', $mode === 'taxi' => ['mode' => $mode, 'minutes' => $car['minutes']],
            $mode === 'transit' && $walk['meters'] > self::TransitWalkMeters => ['mode' => 'transit', 'minutes' => (int) round($car['minutes'] * 1.5) + 10],
            default => ['mode' => 'walk', 'minutes' => $walk['minutes']],
        };
    }

    private static function modeNeedsCarTime(string $mode, int $walkMeters): bool
    {
        return in_array($mode, ['car', 'taxi'], true) || ($mode === 'transit' && $walkMeters > self::TransitWalkMeters);
    }

    private function hasOwnCar(TripDay $day): bool
    {
        return $day->trip->transports->contains('type', 'own_car');
    }

    /**
     * Where the traveller wakes up: lodging with coordinates whose stay includes this day.
     * On the day of moving from one lodging to another the earlier one is taken.
     */
    public function morningLodging(TripDay $day): ?Accommodation
    {
        return $this->lodgingsOfDay($day)->first();
    }

    /**
     * Where the traveller sleeps after this day: like the morning lodging, but not the one they check out of today.
     * On the last day there is none — the day ends at the last place.
     */
    public function eveningLodging(TripDay $day): ?Accommodation
    {
        return $this->lodgingsOfDay($day)
            ->reject(fn (Accommodation $lodging) => $day->date && $lodging->check_out?->isSameDay($day->date))
            ->last();
    }

    /**
     * Lodgings with coordinates that cover the day (unknown dates do not limit), in order of check-in.
     *
     * @return Collection<int, Accommodation>
     */
    private function lodgingsOfDay(TripDay $day): Collection
    {
        $date = $day->date?->toDateString();

        return $day->trip->accommodations
            ->filter(fn (Accommodation $lodging) => $lodging->latitude !== null && $lodging->longitude !== null)
            ->filter(fn (Accommodation $lodging) => $date === null || (
                ($lodging->check_in === null || $lodging->check_in->toDateString() <= $date)
                && ($lodging->check_out === null || $date <= $lodging->check_out->toDateString())
            ))
            ->sortBy(fn (Accommodation $lodging) => $lodging->check_in?->toDateTimeString() ?? '')
            ->values();
    }

    /**
     * Last day of a city: warn when less than an hour is left before the train/plane "Обратно" or "Дальше".
     *
     * @return array<int, string>
     */
    private function departureWarnings(TripDay $day, int $endMinutes): array
    {
        if (! $day->date) {
            return [];
        }

        return $day->trip->transports
            ->whereIn('direction', ['back', 'onward'])
            ->filter(fn (Transport $transport) => $transport->departure_at?->isSameDay($day->date))
            ->filter(fn (Transport $transport) => self::toMinutes($transport->departure_at->format('H:i')) - $endMinutes < self::DepartureBufferMinutes)
            ->map(fn (Transport $transport) => 'До отправления в '.$transport->departure_at->format('H:i').' остаётся меньше часа — уберите место или начните день раньше.')
            ->values()
            ->all();
    }

    /**
     * Yandex Maps route type (parameter rtt) for our travel modes.
     */
    public const YandexRouteTypes = [
        'walk' => 'pd',
        'transit' => 'mt',
        'car' => 'auto',
        'taxi' => 'taxi',
    ];

    /**
     * Link to the exact route in Yandex Maps — an ordinary URL with coordinates, no API.
     *
     * @param  array<int, array{0: float, 1: float}>  $points  two or more points in order
     */
    public static function yandexRouteUrl(array $points, ?string $mode): string
    {
        // rtext — точки через «~» в формате «широта,долгота», rtt — способ: pd пешком, mt транспорт, auto машина.
        $text = implode('~', array_map(fn (array $point) => "{$point[0]},{$point[1]}", $points));
        $type = self::YandexRouteTypes[$mode] ?? self::YandexRouteTypes['walk'];

        return "https://yandex.ru/maps/?rtext={$text}&rtt={$type}";
    }

    /**
     * "10:30" or "10:30:00" → 630 minutes since midnight.
     */
    public static function toMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    /**
     * 630 → "10:30". A schedule that runs past midnight stops at 23:59: visits after midnight are not supported.
     */
    public static function formatTime(int $minutes): string
    {
        $minutes = min($minutes, self::LastMinuteOfDay);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
