<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_landing_page_with_real_statistics(): void
    {
        Trip::factory()->count(2)->create();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Home')
                ->where('stats.trips', 2)
                ->where('stats.users', 2)
                ->where('stats.places', 0)
                ->where('auth.user', null));
    }

    public function test_authenticated_user_also_sees_landing_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Home'));
    }

    public function test_header_receives_avatar_url_and_initials_of_current_user(): void
    {
        $user = User::factory()->create(['name' => 'Анна Петрова', 'avatar' => 'avatars/anna.jpg']);

        $this->actingAs($user)
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.name', 'Анна Петрова')
                ->where('auth.user.initials', 'АП')
                ->where('auth.user.avatarUrl', $user->avatarUrl())
                ->missing('auth.user.password')
                ->where('auth.isAdmin', false));
    }

    public function test_header_shows_initials_when_user_has_no_avatar(): void
    {
        $this->actingAs(User::factory()->create(['avatar' => null]))
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.avatarUrl', null));
    }
}
