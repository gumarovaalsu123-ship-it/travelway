<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['trip_id', 'day_number', 'date', 'title', 'start_time', 'return_minutes', 'return_mode'])]
class TripDay extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'return_minutes' => 'integer',
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
     * Insert a place right after another place of this day ("Что по пути?" puts it between two places).
     * All places after $after move one position down, so there is room for the new one:
     * 1, 2, [3, 4] → 1, 2, new 3, [4, 5]. Without $after the place goes to the end of the day.
     */
    public function insertPlaceAfter(Place $place, ?TripPlace $after): TripPlace
    {
        if ($after === null) {
            return $this->addPlace($place);
        }

        return DB::transaction(function () use ($place, $after) {
            $this->tripPlaces()->reorder()
                ->where('visit_order', '>', $after->visit_order)
                ->increment('visit_order');

            return $this->tripPlaces()->create([
                'trip_id' => $this->trip_id,
                'place_id' => $place->id,
                'visit_order' => $after->visit_order + 1,
            ]);
        });
    }

    /**
     * Forget the calculated travel times of the day. Called when the order of places changes:
     * old segments would connect other places, so it is more honest to show nothing until the next calculation.
     */
    public function forgetTravelTimes(): void
    {
        $this->tripPlaces()->reorder()->update(['travel_minutes' => null, 'calculated_mode' => null]);
        $this->update(['return_minutes' => null, 'return_mode' => null]);
    }
}
