<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Buttons "Открыть доступ по ссылке" / "Закрыть доступ" in the trip "Settings".
 * The link itself is /t/{token}, see PublicTripController.
 */
class TripShareController extends Controller
{
    public function store(Trip $trip): RedirectResponse
    {
        Gate::authorize('share', $trip);

        $trip->openPublicAccess();

        Inertia::flash('toast', 'Доступ по ссылке открыт. Скопируйте ссылку и отправьте её друзьям.');

        return back();
    }

    public function destroy(Trip $trip): RedirectResponse
    {
        Gate::authorize('share', $trip);

        $trip->closePublicAccess();

        Inertia::flash('toast', 'Доступ закрыт — старая ссылка больше не работает.');

        return back();
    }
}
