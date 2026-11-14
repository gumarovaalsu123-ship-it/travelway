<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TripPlanController extends Controller
{
    public function index(Trip $trip): Response
    {
        Gate::authorize('view', $trip);

        return Inertia::render('Trips/Plan', [
            'trip' => $trip->toHeader(),
        ]);
    }
}
