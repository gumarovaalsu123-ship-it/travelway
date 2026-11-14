<?php

namespace App\Models;

use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'previous_trip_id', 'title', 'description', 'destination', 'start_date', 'end_date', 'days_count', 'cover_image', 'is_public'])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    public const StatusDraft = 'draft';

    public const StatusCurrent = 'current';

    public const StatusPlanned = 'planned';

    public const StatusCompleted = 'completed';

    public const MaxDays = 60;

    /**
     * When the dates are known, the number of days always follows from them.
     */
    protected static function booted(): void
    {
        static::saving(function (Trip $trip) {
            if ($trip->hasDates()) {
                $trip->days_count = (int) $trip->start_date->diffInDays($trip->end_date) + 1;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days_count' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TripDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(TripDay::class)->orderBy('day_number');
    }

    /**
     * @return HasMany<TripPlace, $this>
     */
    public function tripPlaces(): HasMany
    {
        return $this->hasMany(TripPlace::class);
    }

    /**
     * @return HasMany<Transport, $this>
     */
    public function transports(): HasMany
    {
        return $this->hasMany(Transport::class);
    }

    /**
     * @return HasMany<Accommodation, $this>
     */
    public function accommodations(): HasMany
    {
        return $this->hasMany(Accommodation::class);
    }

    /**
     * @return HasMany<PackingList, $this>
     */
    public function packingLists(): HasMany
    {
        return $this->hasMany(PackingList::class)->orderBy('position');
    }

    /**
     * All things to pack, from every group of the trip.
     * Also used by scoped route bindings: /my-trips/{trip}/packing-items/{packingItem}.
     *
     * @return HasManyThrough<PackingItem, PackingList, $this>
     */
    public function packingItems(): HasManyThrough
    {
        return $this->hasManyThrough(PackingItem::class, PackingList::class);
    }

    /**
     * The traveller's own to-dos on the "Заранее" tab (tickets and bookings are not stored, they are computed).
     *
     * @return HasMany<TripTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(TripTask::class);
    }

    /**
     * Цепочка городов: поездка, из которой сюда приехали переездом «Дальше».
     * Что это поездка того же пользователя, проверяет TripRequest при создании.
     *
     * @return BelongsTo<Trip, $this>
     */
    public function previousTrip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'previous_trip_id');
    }

    /**
     * Следующий город цепочки. Следующая поездка у каждой одна (unique в миграции).
     *
     * @return HasOne<Trip, $this>
     */
    public function nextTrip(): HasOne
    {
        return $this->hasOne(Trip::class, 'previous_trip_id');
    }

    /**
     * Последний переезд «Дальше» (при пересадках их несколько) — по нему видно,
     * в какой город и когда человек едет дальше. Записи без времени считаются самыми ранними.
     */
    public function lastOnwardRide(): ?Transport
    {
        return $this->transports
            ->where('direction', 'onward')
            ->sortBy(fn (Transport $ride) => [$ride->departure_at?->timestamp ?? 0, $ride->id])
            ->last();
    }

    public function hasDates(): bool
    {
        return $this->start_date !== null && $this->end_date !== null;
    }

    /**
     * The day from ?day=N on the "Day plan" and "Map" tabs. If there is no such day (no parameter, garbage,
     * or the trip got shorter), open today's day of a trip that is happening now, otherwise day 1.
     *
     * @param  Collection<int, TripDay>  $days  days of this trip
     */
    public function dayByNumber(Collection $days, int $requestedNumber): ?TripDay
    {
        $day = $days->firstWhere('day_number', $requestedNumber);

        if ($day === null && $this->status === self::StatusCurrent) {
            // Так же, как «Текущая поездка» на главной: номер сегодняшнего дня от даты начала.
            $todayNumber = (int) $this->start_date->diffInDays(today()) + 1;
            $day = $days->firstWhere('day_number', $todayNumber);
        }

        return $day ?? $days->first();
    }

    /**
     * Draft — dates are not chosen yet; otherwise the status follows from today's date.
     *
     * @return Attribute<string, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if (! $this->hasDates()) {
                return self::StatusDraft;
            }

            $today = today();

            return match (true) {
                $today->lt($this->start_date) => self::StatusPlanned,
                $today->gt($this->end_date) => self::StatusCompleted,
                default => self::StatusCurrent,
            };
        });
    }

    /**
     * Data for the trip page header that every tab shows (see Components/TripLayout.jsx).
     *
     * previousTrip / nextTrip — соседние города цепочки для ссылок «← Предыдущий город / Следующий →».
     *
     * @return array{id: int, title: string, destination: string, startDate: ?string, endDate: ?string, daysCount: int, status: string, placesCount: int, previousTrip: ?array{id: int, destination: string}, nextTrip: ?array{id: int, destination: string}}
     */
    public function toHeader(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'destination' => $this->destination,
            'startDate' => $this->start_date?->toDateString(),
            'endDate' => $this->end_date?->toDateString(),
            'daysCount' => $this->days_count,
            'status' => $this->status,
            'placesCount' => $this->tripPlaces()->count(),
            'previousTrip' => $this->previousTrip?->only(['id', 'destination']),
            'nextTrip' => $this->nextTrip?->only(['id', 'destination']),
        ];
    }

    /**
     * Make the trip days match the trip: one day per date (or per planned day for a draft).
     * Days beyond the new length are removed together with the places planned on them.
     */
    public function syncDays(): void
    {
        for ($number = 1; $number <= $this->days_count; $number++) {
            $this->days()->updateOrCreate(
                ['day_number' => $number],
                ['date' => $this->start_date?->copy()->addDays($number - 1)->toDateString()],
            );
        }

        $this->days()->where('day_number', '>', $this->days_count)->delete();
    }
}
