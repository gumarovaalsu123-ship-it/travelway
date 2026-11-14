<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['trip_id', 'place_id', 'trip_day_id', 'start_time', 'end_time', 'visit_order', 'note'])]
class TripPlace extends Model
{
    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<Place, $this>
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * @return BelongsTo<TripDay, $this>
     */
    public function day(): BelongsTo
    {
        return $this->belongsTo(TripDay::class, 'trip_day_id');
    }

    /**
     * Swap this place with the one right above it in the same day.
     * The first place of the day has nobody above, then nothing changes.
     */
    public function moveUp(): void
    {
        // Сосед сверху — место того же дня с наибольшим visit_order, который меньше нашего.
        $neighbour = TripPlace::query()
            ->where('trip_day_id', $this->trip_day_id)
            ->where('visit_order', '<', $this->visit_order)
            ->orderByDesc('visit_order')
            ->first();

        $this->swapOrderWith($neighbour);
    }

    /**
     * Swap this place with the one right below it in the same day.
     * The last place of the day has nobody below, then nothing changes.
     */
    public function moveDown(): void
    {
        // Сосед снизу — место того же дня с наименьшим visit_order, который больше нашего.
        $neighbour = TripPlace::query()
            ->where('trip_day_id', $this->trip_day_id)
            ->where('visit_order', '>', $this->visit_order)
            ->orderBy('visit_order')
            ->first();

        $this->swapOrderWith($neighbour);
    }

    /**
     * Move the place to another day of the trip. It goes to the end of that day,
     * its time and note stay as they were.
     */
    public function moveToDay(TripDay $day): void
    {
        $this->update([
            'trip_day_id' => $day->id,
            'visit_order' => (int) $day->tripPlaces()->max('visit_order') + 1,
        ]);
    }

    /**
     * Exchange visit_order values with the neighbour. Gaps in the numbering (after a place was removed)
     * do not matter: only the order of the numbers is important.
     */
    protected function swapOrderWith(?TripPlace $neighbour): void
    {
        if ($neighbour === null) {
            return;
        }

        // Транзакция: оба места сохраняются вместе, иначе при сбое порядок мог бы сломаться наполовину.
        DB::transaction(function () use ($neighbour) {
            [$this->visit_order, $neighbour->visit_order] = [$neighbour->visit_order, $this->visit_order];

            $this->save();
            $neighbour->save();
        });
    }
}
