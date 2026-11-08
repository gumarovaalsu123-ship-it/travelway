<?php

namespace App\Models;

use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['user_id', 'title', 'description', 'destination', 'start_date', 'end_date', 'days_count', 'cover_image', 'is_public'])]
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

    public function hasDates(): bool
    {
        return $this->start_date !== null && $this->end_date !== null;
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
     * @return array{id: int, title: string, destination: string, startDate: ?string, endDate: ?string, daysCount: int, status: string, placesCount: int}
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
