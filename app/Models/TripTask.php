<?php

namespace App\Models;

use Database\Factories\TripTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A traveller's own to-do before the trip, for example "Оформить страховку" (the "Заранее" tab).
 */
#[Fillable(['trip_id', 'title', 'due_date', 'is_done'])]
class TripTask extends Model
{
    /** @use HasFactory<TripTaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'is_done' => 'boolean',
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
