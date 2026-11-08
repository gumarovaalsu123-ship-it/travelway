<?php

namespace App\Models;

use Database\Factories\AccommodationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the traveller stays during the trip: a hotel, an apartment, a hostel...
 */
#[Fillable(['name', 'address', 'check_in', 'check_out', 'booking_info', 'latitude', 'longitude', 'note'])]
class Accommodation extends Model
{
    /** @use HasFactory<AccommodationFactory> */
    use HasFactory;

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
