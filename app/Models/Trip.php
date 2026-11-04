<?php

namespace App\Models;

use Carbon\CarbonPeriod;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'title', 'description', 'destination', 'start_date', 'end_date', 'cover_image', 'status', 'is_public'])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
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
     * Create the missing trip days so that every date between start and end has a day.
     */
    public function syncDays(): void
    {
        $period = CarbonPeriod::create($this->start_date, $this->end_date);

        foreach ($period as $index => $date) {
            $this->days()->firstOrCreate(
                ['day_number' => $index + 1],
                ['date' => $date->toDateString()],
            );
        }

        $this->days()->where('day_number', '>', $period->count())->delete();
    }
}
