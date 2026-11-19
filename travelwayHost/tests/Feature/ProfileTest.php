<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->get('/profile/edit')->assertRedirect('/login');
        $this->put('/profile/password')->assertRedirect('/login');
    }

    public function test_profile_shows_correct_statistics(): void
    {
        $this->travelTo('2026-06-15');
        $user = User::factory()->create(['name' => 'Александр Волков']);

        // Две завершённые поездки (3 + 2 дня), одна в том же городе, но с другим регистром.
        $this->tripWithPlaces($user, 'Казань', '2026-05-01', '2026-05-03', 2);
        $this->tripWithPlaces($user, ' казань ', '2026-05-10', '2026-05-11', 1);
        // Текущая: идёт третий день — в пути 3 дня, места считаются запланированными.
        $this->tripWithPlaces($user, 'Сочи', '2026-06-13', '2026-06-20', 4);
        // Запланированные: ближайшая — Псков, а Тверь позже.
        $this->tripWithPlaces($user, 'Тверь', '2026-08-01', '2026-08-02', 0);
        $this->tripWithPlaces($user, 'Псков', '2026-07-01', '2026-07-02', 1);
        // Черновик без дат тоже «запланирован».
        Trip::factory()->for($user)->create(['destination' => 'Минск', 'start_date' => null, 'end_date' => null, 'days_count' => 2]);

        $user->favoritePlaces()->attach(Place::factory()->count(3)->create(), ['created_at' => now()]);

        // Чужие поездки не считаются.
        $this->tripWithPlaces(User::factory()->create(), 'Омск', '2026-05-01', '2026-05-03', 5);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile/Show')
                ->where('profile.name', 'Александр Волков')
                ->where('profile.initials', 'АВ')
                ->where('profile.avatarUrl', null)
                ->where('stats.trips', ['total' => 6, 'completed' => 2, 'planned' => 3, 'current' => 1])
                ->where('stats.daysTraveled', 3 + 2 + 3)
                ->where('stats.places', ['visited' => 3, 'planned' => 5])
                ->where('stats.cities', 2)
                ->where('stats.favorites', 3)
                // Пока идёт текущая поездка, ближайшая — она.
                ->where('stats.nextTrip.destination', 'Сочи')
                ->where('stats.nextTrip.status', Trip::StatusCurrent)
            );

    }

    public function test_next_trip_is_the_earliest_planned_one(): void
    {
        $this->travelTo('2026-06-15');
        $user = User::factory()->create();
        $this->tripWithPlaces($user, 'Тверь', '2026-08-01', '2026-08-02', 0);
        $pskov = $this->tripWithPlaces($user, 'Псков', '2026-06-25', '2026-06-26', 0);

        $this->actingAs($user)
            ->get('/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.nextTrip.id', $pskov->id)
                ->where('stats.nextTrip.daysLeft', 10)
            );
    }

    public function test_profile_of_new_user_has_zero_statistics(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.trips.total', 0)
                ->where('stats.daysTraveled', 0)
                ->where('stats.nextTrip', null)
            );
    }

    public function test_edit_page_splits_name_into_first_and_last(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Анна Мария Петрова']))
            ->get('/profile/edit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Profile/Edit')
                ->where('profile.firstName', 'Анна')
                ->where('profile.lastName', 'Мария Петрова')
            );
    }

    public function test_user_can_update_name_and_email(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->put('/profile', ['first_name' => 'Иван', 'last_name' => 'Иванов', 'email' => 'new@example.com'])
            ->assertRedirect('/profile')
            ->assertInertiaFlash('toast', 'Профиль сохранён.');

        $user->refresh();
        $this->assertSame('Иван Иванов', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_user_can_keep_own_email(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->actingAs($user)
            ->put('/profile', ['first_name' => 'Иван', 'last_name' => 'Иванов', 'email' => 'me@example.com'])
            ->assertSessionHasNoErrors();
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->actingAs($user)
            ->from('/profile/edit')
            ->put('/profile', ['first_name' => 'Иван', 'last_name' => 'Иванов', 'email' => 'taken@example.com'])
            ->assertRedirect('/profile/edit')
            ->assertSessionHasErrors(['email' => 'Такое значение поля «электронная почта» уже занято.']);

        $this->assertSame('me@example.com', $user->refresh()->email);
    }

    public function test_user_can_upload_avatar_and_old_file_is_deleted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/old.jpg', 'old');
        $user = User::factory()->create(['avatar' => 'avatars/old.jpg']);

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => $this->fakePng('me.png')])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', 'Аватар обновлён.');

        $user->refresh();
        $this->assertStringStartsWith('avatars/', $user->avatar);
        Storage::disk('public')->assertExists($user->avatar);
        Storage::disk('public')->assertMissing('avatars/old.jpg');
    }

    public function test_avatar_must_be_a_small_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('avatar');

        $this->actingAs($user)
            ->post('/profile/avatar', ['avatar' => $this->fakePng('big.png')->size(3000)])
            ->assertSessionHasErrors(['avatar' => 'Аватар должен быть не больше 2 МБ.']);

        $this->assertNull($user->refresh()->avatar);
    }

    public function test_user_can_delete_avatar(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/me.jpg', 'me');
        $user = User::factory()->create(['avatar' => 'avatars/me.jpg']);

        $this->actingAs($user)->delete('/profile/avatar')->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->avatar);
        Storage::disk('public')->assertMissing('avatars/me.jpg');
    }

    public function test_user_can_change_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user)
            ->put('/profile/password', [
                'current_password' => 'old-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', 'Пароль изменён.');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_password_is_not_changed_with_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user)
            ->put('/profile/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasErrors(['current_password' => 'Текущий пароль указан неверно.']);

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }

    public function test_new_password_needs_eight_characters_and_confirmation(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user)
            ->put('/profile/password', [
                'current_password' => 'old-password',
                'password' => 'short',
                'password_confirmation' => 'other',
            ])
            ->assertSessionHasErrors('password');
    }

    /**
     * Настоящая картинка PNG 1×1. UploadedFile::fake()->image() не подходит: ему нужно расширение GD,
     * которого может не быть в PHP. size() — какой размер файла (в КБ) увидит проверка.
     */
    private function fakePng(string $name): File
    {
        $onePixelPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $onePixelPng);
    }

    /**
     * Поездка с датами и указанным числом мест в плане.
     */
    private function tripWithPlaces(User $user, string $destination, string $startDate, string $endDate, int $placesCount): Trip
    {
        $trip = Trip::factory()->for($user)->create([
            'destination' => $destination,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        foreach (Place::factory()->count($placesCount)->create() as $place) {
            $trip->days->first()->addPlace($place);
        }

        return $trip;
    }
}
