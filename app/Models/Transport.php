<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trip leg: a flight, a train, a bus ride, etc. (the "Transport" tab of the trip page).
 * Колонка booking_info (номер брони) в таблице осталась от старой версии формы: её больше не заполняют и не показывают.
 */
#[Table('transport')]
#[Fillable(['trip_id', 'type', 'direction', 'departure_place', 'arrival_place', 'departure_at', 'arrival_at', 'is_booked', 'note'])]
class Transport extends Model
{
    /** @use HasFactory<TransportFactory> */
    use HasFactory;

    /**
     * Allowed transport types. Russian labels are on the page (Pages/Trips/Transport.jsx).
     */
    public const Types = ['plane', 'train', 'bus', 'own_car', 'car', 'ferry', 'other'];

    /**
     * Russian labels of the types for texts made on the server (hints on the "Preparation" tab).
     * Same as TYPE_LABELS in Pages/Trips/Transport.jsx.
     *
     * @var array<string, string>
     */
    public const TypeLabels = [
        'plane' => 'Самолёт',
        'train' => 'Поезд',
        'bus' => 'Автобус',
        'own_car' => 'Своё авто',
        'car' => 'Такси или аренда авто',
        'ferry' => 'Паром',
        'other' => 'Другое',
    ];

    /**
     * Allowed directions: to the destination, back home, or onward to the next city.
     */
    public const Directions = ['to', 'back', 'onward'];

    /**
     * Types that need no ticket: you just get in your own car and go.
     */
    public const TypesWithoutTicket = ['own_car'];

    /**
     * На сколько дней дорога может отличаться от начала или конца поездки:
     * ночной поезд накануне, поздний рейс на следующий день после последнего дня.
     */
    public const DateToleranceDays = 1;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
            'is_booked' => 'boolean',
        ];
    }

    /**
     * Whether a ticket has to be bought for this leg (not for the own car).
     */
    public function needsTicket(): bool
    {
        return ! in_array($this->type, self::TypesWithoutTicket, true);
    }

    /**
     * Какие даты допустимы для дороги этого направления (концепция, 3.1):
     * «Туда» — начало поездки ±1 день, «Обратно» и «Дальше» — конец поездки ±1 день.
     * Возвращает первую и последнюю допустимую дату; null — проверять не с чем
     * (у поездки-черновика нет дат или направление не указано).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function allowedDates(Trip $trip, ?string $direction): ?array
    {
        if (! $trip->hasDates() || ! in_array($direction, self::Directions, true)) {
            return null;
        }

        $anchor = CarbonImmutable::instance($direction === 'to' ? $trip->start_date : $trip->end_date)->startOfDay();

        return [$anchor->subDays(self::DateToleranceDays), $anchor->addDays(self::DateToleranceDays)];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
