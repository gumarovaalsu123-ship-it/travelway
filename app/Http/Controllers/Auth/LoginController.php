<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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

        return redirect()->intended(route('map.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
