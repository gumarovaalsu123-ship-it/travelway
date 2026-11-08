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
     * Landing page with real numbers from the database.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Home', [
            'stats' => [
                'trips' => Trip::count(),
                'places' => Place::count(),
                'users' => User::count(),
            ],
        ]);
    }
}
