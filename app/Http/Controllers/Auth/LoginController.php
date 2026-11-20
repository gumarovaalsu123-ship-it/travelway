<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        // Сначала только проверяем почту и пароль, не входя: заблокированного пускать нельзя.
        if (! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'Неверная почта или пароль.',
            ]);
        }

        $user = Auth::getLastAttempted();

        if ($user->isBlocked()) {
            throw ValidationException::withMessages([
                'email' => 'Ваш аккаунт заблокирован администратором.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        // Страница, с которой гостя отправили на вход. Её могли запомнить для другого аккаунта — например,
        // после выхода нажали «Назад» на своей поездке или в админке. Ведём туда, только если этому пользователю можно.
        $intendedUrl = $request->session()->pull('url.intended');

        if ($intendedUrl !== null && $this->canOpen($user, $intendedUrl)) {
            return redirect()->to($intendedUrl);
        }

        return to_route('map.index');
    }

    /**
     * Whether the user may open the remembered page: the admin panel — only an admin,
     * a trip page (any route with {trip}) — only someone who may view that trip. Unknown address — no.
     */
    private function canOpen(User $user, string $url): bool
    {
        try {
            $route = Route::getRoutes()->match(Request::create($url));
        } catch (HttpException) {
            return false;
        }

        if (in_array('admin', $route->gatherMiddleware(), true) && ! $user->isAdmin()) {
            return false;
        }

        $tripId = $route->parameter('trip');

        if ($tripId !== null) {
            $trip = Trip::query()->find($tripId);

            return $trip !== null && $user->can('view', $trip);
        }

        return true;
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
