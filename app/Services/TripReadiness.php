<?php

namespace App\Services;

use App\Models\Accommodation;
use App\Models\PackingItem;
use App\Models\Transport;
use App\Models\Trip;
use App\Models\TripDay;
use Carbon\CarbonInterface;

/**
 * Проверка готовности поездки — вкладка «Подготовка».
 *
 * Пять шагов: как добраться · где жить · что взять · что сделать заранее · план по дням.
 * Каждый шаг либо готов, либо нет; готовность поездки = 20% за каждый готовый шаг.
 * У шага есть подсказки «чего не хватает» (из-за них шаг не готов) и мягкие предупреждения
 * (на готовность не влияют). Каждая подсказка знает вкладку, где её исправить.
 *
 * Класс только читает данные поездки, ничего не сохраняет. Связи поездки (transports, accommodations,
 * packingItems, days, tripPlaces) лучше загрузить заранее через load()/with() — см. TripController.
 *
 * Как расширять: новое правило — это ещё одна строка в hints/warnings нужного шага.
 * Этап 3 («Заранее») дописывает дела в advanceTasks(), этап 5 (цепочка поездок) — hasWayThere().
 */
class TripReadiness
{
    /**
     * Шаги по порядку: ключ => название и вкладка (ключ из TRIP_TABS в Components/TripLayout.jsx).
     * У «Заранее» своей вкладки пока нет — её добавит этап 3.
     *
     * @var array<string, array{title: string, tab: ?string}>
     */
    public const Steps = [
        'transport' => ['title' => 'Как добраться', 'tab' => 'transport'],
        'accommodation' => ['title' => 'Где жить', 'tab' => 'accommodation'],
        'packing' => ['title' => 'Что взять', 'tab' => 'packing'],
        'advance' => ['title' => 'Что сделать заранее', 'tab' => null],
        'plan' => ['title' => 'План по дням', 'tab' => 'plan'],
    ];

    /**
     * На сколько дней дорога «Туда» может отличаться от начала поездки (ночной поезд накануне).
     */
    public const DepartureToleranceDays = 1;

    /**
     * Месяцы в родительном падеже для подсказок: «16 июня».
     *
     * @var array<int, string>
     */
    protected const MonthsGenitive = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    /**
     * Шаги считаются один раз и запоминаются.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $steps = null;

    public function __construct(public Trip $trip) {}

    /**
     * Всё для страницы: процент готовности и шаги по порядку.
     *
     * @return array{percent: int, steps: array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'percent' => $this->percent(),
            'steps' => array_values($this->steps()),
        ];
    }

    /**
     * Готовность: 100% делим поровну между шагами (5 шагов → по 20%).
     */
    public function percent(): int
    {
        $readySteps = count(array_filter($this->steps(), fn (array $step) => $step['ready']));

        return (int) round($readySteps / count(self::Steps) * 100);
    }

    /**
     * Один шаг по ключу.
     *
     * @return array{key: string, title: string, tab: ?string, ready: bool, summary: string, hints: array<int, array{text: string, tab: ?string}>, warnings: array<int, array{text: string, tab: ?string}>}
     */
    public function step(string $key): array
    {
        return $this->steps()[$key];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function steps(): array
    {
        return $this->steps ??= [
            'transport' => $this->transportStep(),
            'accommodation' => $this->accommodationStep(),
            'packing' => $this->packingStep(),
            'advance' => $this->advanceStep(),
            'plan' => $this->planStep(),
        ];
    }

    /**
     * Собирает шаг. Шаг готов, когда у него нет подсказок; предупреждения на готовность не влияют.
     *
     * @param  array<int, array{text: string, tab: ?string}>  $hints
     * @param  array<int, array{text: string, tab: ?string}>  $warnings
     * @return array<string, mixed>
     */
    protected function makeStep(string $key, string $summary, array $hints, array $warnings = []): array
    {
        return [
            'key' => $key,
            'title' => self::Steps[$key]['title'],
            'tab' => self::Steps[$key]['tab'],
            'ready' => $hints === [],
            'summary' => $summary,
            'hints' => $hints,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{text: string, tab: ?string}
     */
    protected function hint(string $text, ?string $tab): array
    {
        return ['text' => $text, 'tab' => $tab];
    }

    /**
     * «Как добраться»: есть дорога туда и дорога обратно (или дальше, в следующий город).
     *
     * @return array<string, mixed>
     */
    protected function transportStep(): array
    {
        $hasWayThere = $this->hasWayThere();
        $hasWayBack = $this->trip->transports->whereIn('direction', ['back', 'onward'])->isNotEmpty();

        $hints = [];

        if (! $hasWayThere) {
            $hints[] = $this->hint('Добавьте дорогу туда', 'transport');
        }

        if (! $hasWayBack) {
            $hints[] = $this->hint('Добавьте дорогу обратно (или дальше, в следующий город)', 'transport');
        }

        $warnings = [];
        $departureWarning = $this->departureFarFromStartWarning();

        if ($departureWarning) {
            $warnings[] = $this->hint($departureWarning, 'transport');
        }

        $summary = 'Туда — '.($hasWayThere ? 'есть' : 'нет').', обратно — '.($hasWayBack ? 'есть' : 'нет');

        return $this->makeStep('transport', $summary, $hints, $warnings);
    }

    /**
     * Есть ли дорога «Туда». Этап 5 (цепочка поездок) добавит сюда:
     * «или у предыдущей поездки цепочки есть переезд „Дальше“».
     */
    protected function hasWayThere(): bool
    {
        return $this->trip->transports->contains('direction', 'to');
    }

    /**
     * Мягкое предупреждение: первая дорога «Туда» далеко от начала поездки (больше чем на ±1 день).
     */
    protected function departureFarFromStartWarning(): ?string
    {
        if (! $this->trip->hasDates()) {
            return null;
        }

        /** @var Transport|null $firstRide */
        $firstRide = $this->trip->transports
            ->where('direction', 'to')
            ->filter(fn (Transport $ride) => $ride->departure_at !== null)
            ->sortBy('departure_at')
            ->first();

        if (! $firstRide) {
            return null;
        }

        $daysApart = abs((int) $firstRide->departure_at->copy()->startOfDay()->diffInDays($this->trip->start_date, false));

        if ($daysApart <= self::DepartureToleranceDays) {
            return null;
        }

        return 'Дорога туда '.$this->formatDate($firstRide->departure_at)
            .', а поездка начинается '.$this->formatDate($this->trip->start_date).' — проверьте даты';
    }

    /**
     * «Где жить»: каждая ночь поездки покрыта жильём или дорогой.
     * Поездка на 1 день — ночей нет, шаг готов. Без дат ночи проверить нельзя — шаг не готов.
     *
     * @return array<string, mixed>
     */
    protected function accommodationStep(): array
    {
        if (! $this->trip->hasDates()) {
            return $this->makeStep('accommodation', 'Даты не выбраны', [
                $this->hint('Укажите даты, чтобы проверить ночи', 'settings'),
            ]);
        }

        $nightsCount = $this->trip->days_count - 1;

        if ($nightsCount === 0) {
            return $this->makeStep('accommodation', 'Поездка на один день — ночёвка не нужна', []);
        }

        $uncoveredNights = $this->uncoveredNights();
        $hints = [];

        foreach ($this->ranges($uncoveredNights) as [$first, $last]) {
            // Ночь k — между днём k и днём k+1.
            $label = $first === $last ? "Ночь {$first} → ".($first + 1) : "Ночи {$first} → ".($last + 1);
            $dates = $this->formatDateRange($this->dateOfDay($first), $this->dateOfDay($last + 1));
            $hints[] = $this->hint("{$label} ({$dates}) без жилья", 'accommodation');
        }

        $coveredCount = $nightsCount - count($uncoveredNights);

        return $this->makeStep('accommodation', "Ночей с жильём: {$coveredCount} из {$nightsCount}", $hints);
    }

    /**
     * Номера ночей без жилья (ночь k — после дня k). Только для поездки с датами.
     *
     * @return array<int, int>
     */
    public function uncoveredNights(): array
    {
        $nights = [];

        for ($night = 1; $night < $this->trip->days_count; $night++) {
            if (! $this->isNightCovered($this->dateOfDay($night))) {
                $nights[] = $night;
            }
        }

        return $nights;
    }

    /**
     * Ночь, которая начинается вечером $date, покрыта, если:
     * — есть жильё с заездом не позже этой даты и выездом позже неё (часы не важны), или
     * — человек в эту ночь в дороге: транспорт уехал в этот день, а приехал на следующий.
     */
    protected function isNightCovered(CarbonInterface $date): bool
    {
        $day = $date->toDateString();
        $nextDay = $date->copy()->addDay()->toDateString();

        // Даты в формате «2026-06-16» можно сравнивать как строки.
        $inAccommodation = $this->trip->accommodations->contains(fn (Accommodation $stay) => $stay->check_in && $stay->check_out
            && $stay->check_in->toDateString() <= $day
            && $day < $stay->check_out->toDateString());

        $onTheRoad = $this->trip->transports->contains(fn (Transport $ride) => $ride->departure_at && $ride->arrival_at
            && $ride->departure_at->toDateString() === $day
            && $ride->arrival_at->toDateString() === $nextDay);

        return $inAccommodation || $onTheRoad;
    }

    /**
     * «Что взять»: в списке есть вещи, и все они отмечены собранными.
     *
     * @return array<string, mixed>
     */
    protected function packingStep(): array
    {
        $items = $this->trip->packingItems;

        if ($items->isEmpty()) {
            return $this->makeStep('packing', 'Список пуст', [
                $this->hint('Список вещей пуст — добавьте вещи или «Стандартный набор»', 'packing'),
            ]);
        }

        $packedCount = $items->filter(fn (PackingItem $item) => $item->is_completed)->count();
        $total = $items->count();
        $hints = [];

        if ($packedCount < $total) {
            $hints[] = $this->hint('Осталось собрать вещей: '.($total - $packedCount)." из {$total}", 'packing');
        }

        return $this->makeStep('packing', "Собрано: {$packedCount} из {$total}", $hints);
    }

    /**
     * «Что сделать заранее»: все дела выполнены. Дел нет — шаг готов.
     *
     * @return array<string, mixed>
     */
    protected function advanceStep(): array
    {
        $tasks = $this->advanceTasks();

        if ($tasks === []) {
            return $this->makeStep('advance', 'Дел нет', []);
        }

        $hints = [];

        foreach ($tasks as $task) {
            if (! $task['isDone']) {
                $due = $task['dueDate'] ? ' — до '.$this->formatDate($task['dueDate']) : '';
                $hints[] = $this->hint($task['title'].$due, $task['tab']);
            }
        }

        $doneCount = count($tasks) - count($hints);

        return $this->makeStep('advance', "Сделано: {$doneCount} из ".count($tasks), $hints);
    }

    /**
     * Дела «заранее». Они не хранятся, а вычисляются из записей поездки;
     * «выполнено» — это отметка у самой записи (билет куплен, жильё забронировано).
     *
     * Сейчас: билеты (кроме своего авто) и брони жилья (кроме «у знакомых»).
     * Этап 3 добавит сюда места плана с «нужен билет/бронь» и свои дела из trip_tasks —
     * просто ещё циклы, которые кладут дела в тот же список.
     *
     * @return array<int, array{title: string, dueDate: ?CarbonInterface, isDone: bool, tab: ?string}>
     */
    public function advanceTasks(): array
    {
        $tasks = [];

        foreach ($this->trip->transports as $ride) {
            if ($ride->needsTicket()) {
                $tasks[] = [
                    'title' => 'Купить билет: '.Transport::TypeLabels[$ride->type].' '.$ride->departure_place.' → '.$ride->arrival_place,
                    'dueDate' => $ride->departure_at,
                    'isDone' => $ride->is_booked,
                    'tab' => 'transport',
                ];
            }
        }

        foreach ($this->trip->accommodations as $stay) {
            if ($stay->needsBooking()) {
                $tasks[] = [
                    'title' => 'Забронировать: '.$stay->name,
                    'dueDate' => $stay->check_in,
                    'isDone' => $stay->is_booked,
                    'tab' => 'accommodation',
                ];
            }
        }

        // По сроку: ближайшие сверху, дела без срока — в конце.
        usort($tasks, fn (array $a, array $b) => ($a['dueDate']?->timestamp ?? PHP_INT_MAX) <=> ($b['dueDate']?->timestamp ?? PHP_INT_MAX));

        return $tasks;
    }

    /**
     * «План по дням»: в каждом дне есть хотя бы одно место.
     *
     * @return array<string, mixed>
     */
    protected function planStep(): array
    {
        $plannedDayIds = $this->trip->tripPlaces->pluck('trip_day_id')->unique();

        $emptyDays = $this->trip->days
            ->reject(fn (TripDay $day) => $plannedDayIds->contains($day->id))
            ->pluck('day_number')
            ->all();

        $hints = [];

        foreach ($this->ranges($emptyDays) as [$first, $last]) {
            $label = $first === $last ? "День {$first}" : "Дни {$first}–{$last}";

            if ($this->trip->hasDates()) {
                $label .= ' ('.$this->formatDateRange($this->dateOfDay($first), $this->dateOfDay($last)).')';
            }

            $hints[] = $this->hint("{$label} без мест", 'plan');
        }

        $daysCount = $this->trip->days->count();
        $plannedCount = $daysCount - count($emptyDays);

        return $this->makeStep('plan', "Дней с местами: {$plannedCount} из {$daysCount}", $hints);
    }

    /**
     * Дата дня поездки по номеру (день 1 — дата начала). Только для поездки с датами.
     */
    protected function dateOfDay(int $dayNumber): CarbonInterface
    {
        return $this->trip->start_date->copy()->addDays($dayNumber - 1);
    }

    /**
     * Склеивает соседние номера в отрезки, чтобы не писать подсказку на каждую ночь:
     * [2, 3, 4, 7] → [[2, 4], [7, 7]]. Номера должны идти по возрастанию.
     *
     * @param  array<int, int>  $numbers
     * @return array<int, array{0: int, 1: int}>
     */
    protected function ranges(array $numbers): array
    {
        $ranges = [];

        foreach ($numbers as $number) {
            $lastIndex = count($ranges) - 1;

            if ($lastIndex >= 0 && $ranges[$lastIndex][1] === $number - 1) {
                $ranges[$lastIndex][1] = $number;
            } else {
                $ranges[] = [$number, $number];
            }
        }

        return $ranges;
    }

    /**
     * «16 июня»
     */
    protected function formatDate(CarbonInterface $date): string
    {
        return $date->day.' '.self::MonthsGenitive[$date->month];
    }

    /**
     * «16–17 июня» или «30 июня – 1 июля».
     */
    protected function formatDateRange(CarbonInterface $from, CarbonInterface $to): string
    {
        if ($from->month === $to->month) {
            return $from->day.'–'.$this->formatDate($to);
        }

        return $this->formatDate($from).' – '.$this->formatDate($to);
    }
}
