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
#[Fillable(['trip_id', 'type', 'departure_place', 'arrival_place', 'departure_at', 'arrival_at', 'booking_info', 'note'])]
class Transport extends Model
{
    /** @use HasFactory<TransportFactory> */
    use HasFactory;

    /**
     * Allowed transport types. Russian labels are on the page (Pages/Trips/Transport.jsx).
     */
    public const Types = ['plane', 'train', 'bus', 'car', 'ferry', 'other'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
