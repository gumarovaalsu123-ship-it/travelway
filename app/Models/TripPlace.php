<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'trip_id', 'place_id', 'trip_day_id', 'start_time', 'end_time', 'visit_order', 'note',
    'stay_minutes', 'travel_mode', 'travel_minutes', 'calculated_mode', 'time_locked',
    'needs_booking', 'is_booked',
])]
class TripPlace extends Model
{
    /**
     * Ways to get to the place. Russian labels are on the page (Pages/Trips/Plan.jsx).
     * taxi — такси или каршеринг, время как у машины.
     */
    public const TravelModes = ['walk', 'transit', 'car', 'taxi'];

    /**
     * How long to stay at a place by default, in minutes, by category slug (concept, section 3.5).
     */
    public const DefaultStayMinutes = [
        'museum' => 120,
        'cafe' => 60,
        'restaurant' => 60,
        'attraction' => 60,
        'park' => 90,
        'viewpoint' => 30,
        'shop' => 30,
    ];

    /**
     * Stay for categories that are not in the table above ("Другое", "Отель"...).
     */
    public const FallbackStayMinutes = 60;

    /**
     * Categories where "нужна бронь" means a table, not a ticket (concept, section 3.4).
     */
    public const TableBookingCategories = ['restaurant'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stay_minutes' => 'integer',
            'travel_minutes' => 'integer',
            'time_locked' => 'boolean',
            'needs_booking' => 'boolean',
            'is_booked' => 'boolean',
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
     * Minutes to stay at the place: what the traveller entered, otherwise the default for the category.
     * Needs the place with its category loaded.
     */
    public function stayMinutes(): int
    {
        return $this->stay_minutes ?? self::defaultStayMinutes($this->place->category?->slug);
    }

    /**
     * For a restaurant one books a table, for other places one buys a ticket. Needs the place with its category loaded.
     */
    public function needsTableBooking(): bool
    {
        return in_array($this->place->category?->slug, self::TableBookingCategories, true);
    }

    public static function defaultStayMinutes(?string $categorySlug): int
    {
        return self::DefaultStayMinutes[$categorySlug] ?? self::FallbackStayMinutes;
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
