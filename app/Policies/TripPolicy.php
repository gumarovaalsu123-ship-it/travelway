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
     * Only the owner can change their trip.
     */
    public function update(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }

    /**
     * Only the owner can delete their trip.
     */
    public function delete(User $user, Trip $trip): bool
    {
        return $trip->user_id === $user->id;
    }
}
