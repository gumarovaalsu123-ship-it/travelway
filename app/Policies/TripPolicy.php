<?php

namespace App\Policies;

use App\Models\Trip;
use App\Models\User;

class TripPolicy
{
    /**
     * Only the owner can open their trip page (trips are private, sharing is out of scope).
     */
    public function view(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    /**
     * Only the owner can change their trip: the plan, places, tickets, lodging, things, to-dos.
     * A completed trip is read-only — it is a memory of the trip, not a plan any more.
     */
    public function update(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id && $trip->status !== Trip::StatusCompleted;
    }

    /**
     * The "Settings" form (title, city, dates). Allowed even for a completed trip:
     * if the dates were typed wrong, the trip would otherwise be stuck as completed for good.
     */
    public function updateSettings(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    /**
     * Only the owner can delete their trip, in any state.
     */
    public function delete(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }
}
