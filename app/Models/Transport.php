<?php

namespace App\Models;

use Database\Factories\TransportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trip leg: a flight, a train, a bus ride, etc. (the "Transport" tab of the trip page).
 */
#[Table('transport')]
#[Fillable(['trip_id', 'type', 'direction', 'departure_place', 'arrival_place', 'departure_at', 'arrival_at', 'booking_info', 'is_booked', 'note'])]
class Transport extends Model
{
    /** @use HasFactory<TransportFactory> */
    use HasFactory;

    /**
     * Allowed transport types. Russian labels are on the page (Pages/Trips/Transport.jsx).
     */
    public const Types = ['plane', 'train', 'bus', 'own_car', 'car', 'ferry', 'other'];

    /**
     * Allowed directions: to the destination, back home, or onward to the next city.
     */
    public const Directions = ['to', 'back', 'onward'];

    /**
     * Types that need no ticket: you just get in your own car and go.
     */
    public const TypesWithoutTicket = ['own_car'];

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
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
