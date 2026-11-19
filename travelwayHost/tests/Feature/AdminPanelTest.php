<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\Role;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create(['role_id' => Role::Admin, ...$attributes]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_regular_user_gets_403(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();
        $this->actingAs($user)->patch("/admin/users/{$target->id}/block")->assertForbidden();
        $this->actingAs($user)->delete("/admin/users/{$target->id}")->assertForbidden();

        $this->assertFalse($target->fresh()->isBlocked());
    }

    public function test_admin_sees_dashboard_with_stats(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['created_at' => now()->subMonth()]);
        Trip::factory()->for($user)->count(2)->create();
        Place::factory()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Dashboard')
                ->where('stats.users.total', 2)
                // Пользователь зарегистрирован месяц назад — в «за неделю» попадает только админ.
                ->where('stats.users.week', 1)
                ->where('stats.trips.total', 2)
                ->where('stats.places.total', 1)
                ->has('chart', 6));
    }

    public function test_admin_link_is_shared_only_for_admin(): void
    {
        $this->actingAs($this->admin())->get('/favorites')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.isAdmin', true));

        $this->actingAs(User::factory()->create())->get('/favorites')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.isAdmin', false));
    }

    public function test_admin_sees_users_with_trip_count_and_can_search(): void
    {
        $admin = $this->admin(['name' => 'Главный Админ']);
        $anna = User::factory()->create(['name' => 'Анна Петрова', 'email' => 'anna@example.com']);
        Trip::factory()->for($anna)->count(3)->create();
        User::factory()->create(['name' => 'Борис', 'email' => 'boris@example.com']);

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Users')
                ->where('users.total', 3));

        $this->actingAs($admin)->get('/admin/users?search=anna@')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('users.total', 1)
                ->where('users.data.0.name', 'Анна Петрова')
                ->where('users.data.0.tripsCount', 3)
                ->where('users.data.0.isAdmin', false));

        $this->actingAs($admin)->get('/admin/users?search=Борис')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('users.total', 1));
    }

    public function test_users_list_is_paginated(): void
    {
        $admin = $this->admin();
        User::factory()->count(20)->create();

        $this->actingAs($admin)->get('/admin/users?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('users.current_page', 2)
                ->where('users.last_page', 2)
                ->has('users.data', 6));
    }

    public function test_blocked_user_cannot_log_in(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/block")
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast');
        $this->assertTrue($user->fresh()->isBlocked());

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertSessionHasErrors(['email' => 'Ваш аккаунт заблокирован администратором.']);
        $this->assertGuest();
    }

    public function test_unblocked_user_can_log_in_again(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['password' => 'secret-password', 'blocked_at' => now()]);

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/unblock");
        $this->assertFalse($user->fresh()->isBlocked());

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect('/interactive-map');
        $this->assertAuthenticatedAs($user);
    }

    public function test_logged_in_user_is_logged_out_after_being_blocked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/favorites')->assertOk();

        // Пока он на сайте, администратор его блокирует.
        $user->forceFill(['blocked_at' => now()])->save();

        $this->get('/favorites')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Ваш аккаунт заблокирован администратором.']);
        $this->assertGuest();
    }

    public function test_admin_can_change_role(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/role", ['role_id' => Role::Admin])
            ->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->isAdmin());

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/role", ['role_id' => Role::User])
            ->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_role_must_be_user_or_admin(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/role", ['role_id' => 99])
            ->assertSessionHasErrors('role_id');
        $this->assertSame(Role::User, $user->fresh()->role_id);
    }

    public function test_admin_cannot_block_demote_or_delete_himself(): void
    {
        $admin = $this->admin();
        // Второй админ есть, значит дело не в «последнем администраторе», а именно в «самом себе».
        $this->admin();

        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/block")
            ->assertSessionHasErrors(['user' => 'Нельзя заблокировать, понизить или удалить самого себя.']);
        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/role", ['role_id' => Role::User])
            ->assertSessionHasErrors('user');
        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")
            ->assertSessionHasErrors('user');

        $admin->refresh();
        $this->assertFalse($admin->isBlocked());
        $this->assertTrue($admin->isAdmin());
    }

    public function test_last_admin_cannot_be_demoted_blocked_or_deleted(): void
    {
        $admin = $this->admin();
        $message = 'Это единственный администратор — без него в админ-панель некому будет войти.';

        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/role", ['role_id' => Role::User])
            ->assertSessionHasErrors(['user' => $message]);
        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/block")
            ->assertSessionHasErrors(['user' => $message]);
        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")
            ->assertSessionHasErrors(['user' => $message]);

        $this->assertModelExists($admin);
        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_blocked_admin_does_not_count_as_another_admin(): void
    {
        $admin = $this->admin();
        $this->admin(['blocked_at' => now()]);

        $this->assertTrue($admin->isLastAdmin());
    }

    public function test_admin_can_block_another_admin_while_he_remains(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();

        $this->actingAs($admin)->patch("/admin/users/{$otherAdmin->id}/block")
            ->assertSessionHasNoErrors();
        $this->assertTrue($otherAdmin->fresh()->isBlocked());
    }

    public function test_admin_can_delete_user_with_trips(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $trip = Trip::factory()->for($user)->create();

        $this->actingAs($admin)->delete("/admin/users/{$user->id}")
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertModelMissing($user);
        $this->assertModelMissing($trip);
    }
}
