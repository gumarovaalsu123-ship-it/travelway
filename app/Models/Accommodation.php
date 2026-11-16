<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AccommodationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the traveller stays during the trip: a hotel, an apartment, a hostel...
 */
#[Fillable(['type', 'name', 'address', 'check_in', 'check_out', 'booking_info', 'is_booked', 'latitude', 'longitude', 'note'])]
class Accommodation extends Model
{
    /** @use HasFactory<AccommodationFactory> */
    use HasFactory;

    /**
     * Allowed accommodation types. Russian labels are on the page (Pages/Trips/Accommodation.jsx).
     */
    public const Types = ['hotel', 'apartment', 'hostel', 'friends', 'other'];

    /**
     * Types that need no booking: staying with friends or relatives.
     */
    public const TypesWithoutBooking = ['friends'];

    /**
     * На сколько дней жильё может выходить за даты поездки: приехали накануне вечером
     * или выезжаем утром на следующий день после последнего дня.
     */
    public const DateToleranceDays = 1;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'is_booked' => 'boolean',
        ];
    }

    /**
     * Whether this place has to be booked (not when staying with friends).
     */
    public function needsBooking(): bool
    {
        return ! in_array($this->type, self::TypesWithoutBooking, true);
    }

    /**
     * Допустимые даты заезда и выезда (первая и последняя дата каждого промежутка):
     * заезд — с дня до начала поездки по последний день, выезд — с первого дня по день после конца.
     * null — у поездки-черновика дат нет, проверять не с чем.
     *
     * @return array{checkIn: array{0: CarbonImmutable, 1: CarbonImmutable}, checkOut: array{0: CarbonImmutable, 1: CarbonImmutable}}|null
     */
    public static function allowedDates(Trip $trip): ?array
    {
        if (! $trip->hasDates()) {
            return null;
        }

        $start = CarbonImmutable::instance($trip->start_date)->startOfDay();
        $end = CarbonImmutable::instance($trip->end_date)->startOfDay();

        return [
            'checkIn' => [$start->subDays(self::DateToleranceDays), $end],
            'checkOut' => [$start, $end->addDays(self::DateToleranceDays)],
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * Nights between the check-in and check-out dates (the hours do not matter), null when a date is unknown.
     */
    public function nights(): ?int
    {
        if (! $this->check_in || ! $this->check_out) {
            return null;
        }

        return (int) $this->check_in->copy()->startOfDay()->diffInDays($this->check_out->copy()->startOfDay());
    }
}
