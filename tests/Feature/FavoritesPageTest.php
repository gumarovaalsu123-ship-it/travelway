<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FavoritesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_see_favorites(): void
    {
        $this->get('/favorites')->assertRedirect('/login');
    }

    public function test_index_lists_only_own_favorites_newest_first(): void
    {
        $user = User::factory()->create();
        $category = PlaceCategory::firstWhere('slug', 'viewpoint');

        $older = Place::factory()->create(['name' => 'Старое', 'category_id' => $category->id]);
        $newer = Place::factory()->create(['name' => 'Новое']);
        $user->favoritePlaces()->attach($older->id, ['created_at' => now()->subDay()]);
        $user->favoritePlaces()->attach($newer->id, ['created_at' => now()]);

        $someoneElse = User::factory()->create();
        $someoneElse->favoritePlaces()->attach(Place::factory()->create(['name' => 'Чужое'])->id, ['created_at' => now()]);

        $this->actingAs($user)
            ->get('/favorites')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Favorites/Index')
                ->has('places', 2)
                ->where('places.0.name', 'Новое')
                ->where('places.1.name', 'Старое')
                ->where('places.1.category', ['slug' => 'viewpoint', 'name' => $category->name])
            );
    }

    public function test_map_receives_place_to_focus_from_query_string(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/interactive-map?place=42')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('focusPlaceId', 42));
    }
}
