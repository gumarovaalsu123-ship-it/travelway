<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Put the places of the day in order of their start time.
     * Places without time go to the end and keep their previous order.
     */
    public function sortPlacesByTime(): void
    {
        $places = $this->tripPlaces()
            ->reorder()
            ->orderByRaw('start_time is null')
            ->orderBy('start_time')
            ->orderBy('visit_order')
            ->get();

        DB::transaction(function () use ($places) {
            foreach ($places->values() as $index => $place) {
                $place->update(['visit_order' => $index + 1]);
            }
        });
    }
}
