<?php

namespace App\Http\Controllers;

use App\Models\Place;
use App\Models\Trip;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Landing page with real numbers from the database and "Примеры маршрутов" — public trips
     * the admin has chosen in the admin panel (Admin\PublicTripController). A card opens the trip by its public link.
     */
    public function __invoke(): Response
    {
        $examples = Trip::query()
            ->featured()
            ->withCount('tripPlaces')
            ->limit(Trip::LandingExamplesLimit)
            ->get()
            ->map(fn (Trip $trip) => [
                'id' => $trip->id,
                'title' => $trip->title,
                'destination' => $trip->destination,
                'coverImage' => $trip->cover_image,
                'status' => $trip->status,
                'startDate' => $trip->start_date?->toDateString(),
                'endDate' => $trip->end_date?->toDateString(),
                'daysCount' => $trip->days_count,
                'placesCount' => $trip->trip_places_count,
                'publicUrl' => route('trips.public', $trip->public_token),
            ]);

        return Inertia::render('Home', [
            'stats' => [
                'trips' => Trip::count(),
                'places' => Place::count(),
                'users' => User::count(),
            ],
            'examples' => $examples,
        ]);
    }
}
