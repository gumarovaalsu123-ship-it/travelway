<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['trip_id', 'day_number', 'date', 'title'])]
class TripDay extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
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
     * @return HasMany<TripPlace, $this>
     */
    public function tripPlaces(): HasMany
    {
        return $this->hasMany(TripPlace::class)->orderBy('visit_order');
    }

    /**
     * Append a place to the end of this day's plan.
     */
    public function addPlace(Place $place, ?string $startTime = null): TripPlace
    {
        return $this->tripPlaces()->create([
            'trip_id' => $this->trip_id,
            'place_id' => $place->id,
            'start_time' => $startTime,
            'visit_order' => (int) $this->tripPlaces()->max('visit_order') + 1,
        ]);
    }
}
