<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_map_to_login(): void
    {
        $this->get('/interactive-map')->assertRedirect('/login');
    }

    public function test_login_page_is_rendered(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
    }

    public function test_user_can_register_and_gets_user_role(): void
    {
        $response = $this->post('/register', [
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'email' => 'ivan@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'terms' => true,
        ]);

        $response->assertRedirect('/interactive-map');
        $this->assertAuthenticated();

        $user = User::firstWhere('email', 'ivan@example.com');
        $this->assertSame('Иван Иванов', $user->name);
        $this->assertSame(Role::User, $user->role_id);
    }

    public function test_registration_requires_terms_matching_passwords_and_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'email' => 'taken@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'другой-пароль',
        ])->assertSessionHasErrors(['email', 'password', 'terms']);

        $this->assertGuest();
    }

    public function test_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect('/interactive-map');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_cannot_open_login_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect();
    }
}
