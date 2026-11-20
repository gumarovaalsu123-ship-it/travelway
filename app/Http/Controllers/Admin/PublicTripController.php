<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Публичные маршруты" section of the admin panel: every trip opened by link.
 * The admin can look at it, put it into "Примеры маршрутов" on the landing page, or close the link (moderation).
 */
class PublicTripController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('search')->toString());

        $trips = Trip::query()
            ->where('is_public', true)
            ->with('user:id,name')
            ->withCount('tripPlaces')
            // Поиск по названию, городу или имени автора.
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('title', 'like', "%{$search}%")
                ->orWhere('destination', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($query) => $query->where('name', 'like', "%{$search}%"))))
            // Выбранные для главной — сверху.
            ->orderByRaw('featured_at is null')
            ->latest('featured_at')
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Trip $trip) => [
                'id' => $trip->id,
                'title' => $trip->title,
                'destination' => $trip->destination,
                'author' => $trip->user->name,
                'startDate' => $trip->start_date?->toDateString(),
                'endDate' => $trip->end_date?->toDateString(),
                'daysCount' => $trip->days_count,
                'placesCount' => $trip->trip_places_count,
                'publicUrl' => route('trips.public', $trip->public_token),
                'isFeatured' => $trip->featured_at !== null,
            ]);

        return Inertia::render('Admin/PublicTrips', [
            'trips' => $trips,
            'search' => $search,
            'featuredCount' => Trip::query()->featured()->count(),
            'featuredLimit' => Trip::LandingExamplesLimit,
        ]);
    }

    /**
     * Put the trip into "Примеры маршрутов" on the landing page. There are only three cards there,
     * so when the limit is reached the admin first removes one of the chosen trips.
     */
    public function feature(Trip $trip): RedirectResponse
    {
        abort_unless($trip->is_public, 404);

        if (Trip::query()->featured()->count() >= Trip::LandingExamplesLimit) {
            return back()->withErrors(['trip' => 'На главной уже '.Trip::LandingExamplesLimit.' примера. Сначала уберите один из них.']);
        }

        $trip->featured_at = now();
        $trip->save();

        Inertia::flash('toast', "«{$trip->title}» теперь в примерах на главной.");

        return back();
    }

    public function unfeature(Trip $trip): RedirectResponse
    {
        $trip->featured_at = null;
        $trip->save();

        Inertia::flash('toast', "«{$trip->title}» убрана с главной.");

        return back();
    }

    /**
     * Moderation: close the link of somebody else's trip (for example, with rude text). The trip itself stays with the author.
     */
    public function close(Trip $trip): RedirectResponse
    {
        $trip->closePublicAccess();

        Inertia::flash('toast', "Доступ по ссылке к «{$trip->title}» закрыт.");

        return back();
    }
}
