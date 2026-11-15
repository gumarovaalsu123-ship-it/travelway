<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvatarRequest;
use App\Http\Requests\PasswordRequest;
use App\Http\Requests\ProfileRequest;
use App\Models\User;
use App\Services\ProfileStatistics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Профиль пользователя: просмотр со статистикой, изменение имени и почты, аватар, смена пароля.
 *
 * Аватары лежат на диске public (storage/app/public/avatars), в браузер попадают по адресу /storage/avatars/...
 * Для этого нужна ссылка public/storage → storage/app/public: один раз выполнить `php artisan storage:link`.
 */
class ProfileController extends Controller
{
    public function show(Request $request, ProfileStatistics $statistics): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Show', [
            'profile' => [
                ...$this->profileData($user),
                'memberSince' => $user->created_at?->toDateString(),
            ],
            'stats' => $statistics->for($user),
        ]);
    }

    public function edit(Request $request): Response
    {
        $user = $request->user();

        // В базе имя хранится одной строкой «Имя Фамилия» (так его сохраняет регистрация),
        // в форме — два поля: всё до первого пробела — имя, остальное — фамилия.
        [$firstName, $lastName] = array_pad(explode(' ', $user->name, 2), 2, '');

        return Inertia::render('Profile/Edit', [
            'profile' => [
                ...$this->profileData($user),
                'firstName' => $firstName,
                'lastName' => $lastName,
            ],
        ]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $request->user()->update([
            'name' => trim($request->validated('first_name').' '.$request->validated('last_name')),
            'email' => $request->validated('email'),
        ]);

        Inertia::flash('toast', 'Профиль сохранён.');

        return to_route('profile.show');
    }

    public function updateAvatar(AvatarRequest $request): RedirectResponse
    {
        $user = $request->user();

        // store() сам придумывает уникальное имя файла, возвращает путь вида «avatars/abc123.jpg».
        $path = $request->file('avatar')->store('avatars', 'public');

        $this->deleteAvatarFile($user);
        $user->update(['avatar' => $path]);

        Inertia::flash('toast', 'Аватар обновлён.');

        return back();
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        $this->deleteAvatarFile($user);
        $user->update(['avatar' => null]);

        Inertia::flash('toast', 'Аватар удалён.');

        return back();
    }

    public function updatePassword(PasswordRequest $request): RedirectResponse
    {
        // Поле password в модели User с приведением 'hashed' — пароль захешируется сам.
        $request->user()->update(['password' => $request->validated('password')]);

        Inertia::flash('toast', 'Пароль изменён.');

        return back();
    }

    /**
     * Общие для обеих страниц данные: имя, почта, адрес аватара и инициалы для заглушки.
     *
     * @return array{name: string, email: string, avatarUrl: ?string, initials: string}
     */
    private function profileData(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'avatarUrl' => $user->avatarUrl(),
            'initials' => $user->initials(),
        ];
    }

    private function deleteAvatarFile(User $user): void
    {
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }
    }
}
