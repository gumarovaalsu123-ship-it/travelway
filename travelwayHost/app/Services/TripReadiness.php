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
 * packingItems, days, tripPlaces с местом и днём, tasks) лучше загрузить заранее через load()/with() — см. TripController.
 *
 * Как расширять: новое правило — это ещё одна строка в hints/warnings нужного шага.
 * Дела «Заранее» собирает advanceTasks(), этап 5 (цепочка поездок) дописывает hasWayThere().
 */
class TripReadiness
{
    /**
     * Шаги по порядку: ключ => название и вкладка (ключ из TRIP_TABS в Components/TripLayout.jsx).
     *
     * @var array<string, array{title: string, tab: ?string}>
     */
    public const Steps = [
        'transport' => ['title' => 'Как добраться', 'tab' => 'transport'],
        'accommodation' => ['title' => 'Где жить', 'tab' => 'accommodation'],
        'packing' => ['title' => 'Что взять', 'tab' => 'packing'],
        'advance' => ['title' => 'Что сделать заранее', 'tab' => 'advance'],
        'plan' => ['title' => 'План по дням', 'tab' => 'plan'],
    ];

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

        $warnings = array_map(fn (string $text) => $this->hint($text, 'transport'), $this->ridesOutsideTripDatesWarnings());

        $summary = 'Туда — '.($hasWayThere ? 'есть' : 'нет').', обратно — '.($hasWayBack ? 'есть' : 'нет');

        return $this->makeStep('transport', $summary, $hints, $warnings);
    }

    /**
     * Есть ли дорога «Туда»: своя запись «Туда» или, если поездка — следующий город цепочки,
     * переезд «Дальше» у предыдущей поездки (это одна и та же дорога, второй раз её не записывают).
     * Если предыдущую поездку удалили, previous_trip_id стал null — и дорога туда снова нужна своя.
     */
    protected function hasWayThere(): bool
    {
        return $this->trip->transports->contains('direction', 'to')
            || (bool) $this->trip->previousTrip?->transports->contains('direction', 'onward');
    }

    /**
     * Мягкие предупреждения: дорога не попадает в даты поездки (Transport::allowedDates, допуск ±1 день).
     * Сохранить такую запись форма не даст, поэтому это возможно, только если даты поездки поменяли
     * уже после того, как записали транспорт.
     *
     * @return array<int, string>
     */
    protected function ridesOutsideTripDatesWarnings(): array
    {
        $warnings = [];

        foreach ($this->trip->transports as $ride) {
            $allowed = Transport::allowedDates($this->trip, $ride->direction);
            $date = $ride->departure_at ?? $ride->arrival_at;

            if ($allowed === null || $date === null) {
                continue;
            }

            [$first, $last] = $allowed;
            $isOutside = collect([$ride->departure_at, $ride->arrival_at])->filter()
                ->contains(fn (CarbonInterface $time) => $time->toDateString() < $first->toDateString() || $time->toDateString() > $last->toDateString());

            if (! $isOutside) {
                continue;
            }

            $road = ['to' => 'Дорога туда', 'back' => 'Дорога обратно', 'onward' => 'Переезд дальше'][$ride->direction];
            $tripDate = $ride->direction === 'to'
                ? 'начинается '.$this->formatDate($this->trip->start_date)
                : 'заканчивается '.$this->formatDate($this->trip->end_date);

            $warnings[] = "{$road} {$this->formatDate($date)}, а поездка теперь {$tripDate} — поправьте даты транспорта";
        }

        return $warnings;
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
     * Дела «заранее»: билеты, брони и свои дела человека — одним списком по сроку.
     *
     * Автоматические дела не хранятся, а вычисляются из записей поездки; «выполнено» — это отметка
     * у самой записи (билет куплен, жильё забронировано, у места «куплено»). Так правда одна:
     * отметили билет на вкладке «Транспорт» — дело на «Заранее» тоже стало сделанным.
     * Свои дела («Оформить страховку») хранятся в trip_tasks.
     *
     * kind и id — откуда взялось дело, по ним вкладка «Заранее» ставит отметку у нужной записи.
     *
     * @return array<int, array{kind: string, id: int, title: string, dueDate: ?CarbonInterface, isDone: bool, tab: ?string}>
     */
    public function advanceTasks(): array
    {
        $tasks = [];

        // Билеты на транспорт, кроме своего авто.
        foreach ($this->trip->transports as $ride) {
            if ($ride->needsTicket()) {
                $tasks[] = [
                    'kind' => 'transport',
                    'id' => $ride->id,
                    'title' => 'Купить билет: '.Transport::TypeLabels[$ride->type].' '.$ride->departure_place.' → '.$ride->arrival_place,
                    'dueDate' => $ride->departure_at,
                    'isDone' => $ride->is_booked,
                    'tab' => 'transport',
                ];
            }
        }

        // Брони жилья, кроме «у знакомых».
        foreach ($this->trip->accommodations as $stay) {
            if ($stay->needsBooking()) {
                $tasks[] = [
                    'kind' => 'accommodation',
                    'id' => $stay->id,
                    'title' => 'Забронировать: '.$stay->name,
                    'dueDate' => $stay->check_in,
                    'isDone' => $stay->is_booked,
                    'tab' => 'accommodation',
                ];
            }
        }

        // Места плана, где человек отметил «нужен билет/бронь». Срок — дата дня (у черновика без дат срока нет).
        foreach ($this->trip->tripPlaces as $visit) {
            if ($visit->needs_booking) {
                $action = $visit->needsTableBooking() ? 'Забронировать столик' : 'Купить билет';

                $tasks[] = [
                    'kind' => 'place',
                    'id' => $visit->id,
                    'title' => "{$action}: {$visit->place->name} (день {$visit->day->day_number})",
                    'dueDate' => $visit->day->date,
                    'isDone' => $visit->is_booked,
                    'tab' => 'plan',
                ];
            }
        }

        // Свои дела человека.
        foreach ($this->trip->tasks as $task) {
            $tasks[] = [
                'kind' => 'task',
                'id' => $task->id,
                'title' => $task->title,
                'dueDate' => $task->due_date,
                'isDone' => $task->is_done,
                'tab' => 'advance',
            ];
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
