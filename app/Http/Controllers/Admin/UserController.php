<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManageUserRequest;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Users" section of the admin panel: list with search, block / unblock, role change, deletion.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('search')->toString());

        $users = User::query()
            ->withCount('trips')
            // Поиск по части имени или почты.
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->latest()
            ->paginate(15)
            // Чтобы при переходе по страницам не терялся поиск (?search=...).
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isAdmin' => $user->isAdmin(),
                'isBlocked' => $user->isBlocked(),
                'tripsCount' => $user->trips_count,
                'registeredAt' => $user->created_at?->toDateString(),
                'isCurrent' => $user->is($request->user()),
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    public function block(ManageUserRequest $request, User $user): RedirectResponse
    {
        $user->blocked_at = now();
        $user->save();

        Inertia::flash('toast', "Пользователь {$user->name} заблокирован.");

        return back();
    }

    public function unblock(User $user): RedirectResponse
    {
        $user->blocked_at = null;
        $user->save();

        Inertia::flash('toast', "Пользователь {$user->name} разблокирован.");

        return back();
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        // role_id нет в $fillable (чтобы его нельзя было подсунуть при регистрации), поэтому присваиваем напрямую.
        $user->role_id = $request->integer('role_id');
        $user->save();

        $roleName = $user->isAdmin() ? 'администратор' : 'пользователь';
        Inertia::flash('toast', "Роль {$user->name} изменена: {$roleName}.");

        return back();
    }

    public function destroy(ManageUserRequest $request, User $user): RedirectResponse
    {
        // Поездки удалятся вместе с ним (cascadeOnDelete), а добавленные им места останутся без автора.
        $user->delete();

        Inertia::flash('toast', "Пользователь {$user->name} удалён.");

        return back();
    }
}
