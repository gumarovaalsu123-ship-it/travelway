<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Если пользователя заблокировали, пока он был на сайте, — на следующем же запросе
 * выходим из его аккаунта и отправляем на страницу входа с объяснением.
 */
class LogOutBlockedUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isBlocked()) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->withErrors(['email' => 'Ваш аккаунт заблокирован администратором.']);
        }

        return $next($request);
    }
}
