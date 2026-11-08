<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\PlaceCategory;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Фото и информация о месте из открытых источников: что сервер принимает, сохраняет и отдаёт.
 */
class MapPlaceDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGE_URL = 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Kazan_Kremlin.jpg/320px-Kazan_Kremlin.jpg';

    private const WIKI_URL = 'https://ru.wikipedia.org/wiki/Казанский_кремль';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function placePayload(array $overrides = []): array
    {
        return [
            'name' => 'Казанский кремль',
            'category_id' => PlaceCategory::firstWhere('slug', 'attraction')->id,
            'address' => 'Кремлёвская ул., 2',
            'latitude' => 55.7986,
            'longitude' => 49.1059,
            'destination' => 'favorite',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function storePlace(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->from('/interactive-map')
            ->post('/interactive-map/places', $this->placePayload($overrides));
    }

    public function test_place_is_saved_with_all_details(): void
    {
        $this->storePlace([
            'image' => self::IMAGE_URL,
            'description' => 'Древнейшая часть Казани, объект Всемирного наследия ЮНЕСКО.',
            'opening_hours' => 'Mo-Su 08:00-22:00',
            'website' => 'https://kazan-kremlin.ru',
            'phone' => '+7 843 567-80-01',
            'wiki_url' => self::WIKI_URL,
        ])->assertSessionHasNoErrors()->assertRedirect('/interactive-map');

        $this->assertDatabaseHas('places', [
            'name' => 'Казанский кремль',
            'image' => self::IMAGE_URL,
            'description' => 'Древнейшая часть Казани, объект Всемирного наследия ЮНЕСКО.',
            'opening_hours' => 'Mo-Su 08:00-22:00',
            'website' => 'https://kazan-kremlin.ru',
            'phone' => '+7 843 567-80-01',
            'wiki_url' => self::WIKI_URL,
        ]);
    }

    public function test_place_is_saved_without_details(): void
    {
        $this->storePlace()->assertSessionHasNoErrors();

        $place = Place::firstWhere('name', 'Казанский кремль');

        $this->assertNull($place->image);
        $this->assertNull($place->description);
        $this->assertNull($place->opening_hours);
        $this->assertNull($place->website);
        $this->assertNull($place->phone);
        $this->assertNull($place->wiki_url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignImageUrls(): array
    {
        return [
            'другой домен' => ['https://example.com/a.jpg'],
            'не https' => ['http://upload.wikimedia.org/wikipedia/commons/a/ab/Kazan.jpg'],
            'похожий домен' => ['https://upload.wikimedia.org.evil.com/a.jpg'],
            'похожий домен thumb' => ['https://thumb.wikimedia.org.evil.com/a.jpg'],
            'не ссылка' => ['upload.wikimedia.org/a.jpg'],
        ];
    }

    /**
     * Так сейчас выглядят миниатюры из Wikipedia summary API.
     */
    public function test_image_from_thumb_wikimedia_is_accepted(): void
    {
        $imageUrl = 'https://thumb.wikimedia.org/wikipedia/commons/thumb/d/d1/Kazan_Kremlin_Qolsharif_Mosque.jpg/330px-Kazan_Kremlin_Qolsharif_Mosque.jpg';

        $this->storePlace(['image' => $imageUrl])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('places', ['image' => $imageUrl]);
    }

    #[DataProvider('foreignImageUrls')]
    public function test_image_must_be_https_link_to_wikimedia(string $imageUrl): void
    {
        $this->storePlace(['image' => $imageUrl])->assertSessionHasErrors('image');

        $this->assertDatabaseCount('places', 0);
    }

    public function test_long_wikimedia_image_url_is_saved_completely(): void
    {
        // Так выглядит thumbnail-ссылка статьи с русским названием файла: кириллица в процентной кодировке.
        $fileName = rawurlencode(str_repeat('Казанский_кремль_', 6).'вид.jpg');
        $imageUrl = "https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/{$fileName}/320px-{$fileName}";
        $this->assertGreaterThan(255, strlen($imageUrl));

        $this->storePlace(['image' => $imageUrl])->assertSessionHasNoErrors();

        $this->assertSame($imageUrl, Place::firstWhere('name', 'Казанский кремль')->image);
    }

    public function test_wiki_url_must_lead_to_wikipedia(): void
    {
        $this->storePlace(['wiki_url' => 'https://example.com/wiki/Казанский_кремль'])->assertSessionHasErrors('wiki_url');
        $this->storePlace(['wiki_url' => 'https://wikipedia.org.evil.com/wiki/Kazan'])->assertSessionHasErrors('wiki_url');

        $this->storePlace(['wiki_url' => self::WIKI_URL])->assertSessionHasNoErrors();
        $this->storePlace(['wiki_url' => 'https://en.wikipedia.org/wiki/Kazan_Kremlin'])->assertSessionHasNoErrors();
    }

    public function test_website_must_be_http_or_https(): void
    {
        $this->storePlace(['website' => 'javascript:alert(1)'])->assertSessionHasErrors('website');
        $this->storePlace(['website' => 'ftp://kazan-kremlin.ru/file'])->assertSessionHasErrors('website');
        $this->assertDatabaseCount('places', 0);

        $this->storePlace(['website' => 'https://kazan-kremlin.ru'])->assertSessionHasNoErrors();
        $this->storePlace(['website' => 'http://kazan-kremlin.ru'])->assertSessionHasNoErrors();
    }

    public function test_description_is_limited_to_2000_characters(): void
    {
        $this->storePlace(['description' => Str::repeat('а', 2001)])->assertSessionHasErrors('description');

        $this->storePlace(['description' => Str::repeat('а', 2000)])->assertSessionHasNoErrors();
    }

    public function test_phone_and_opening_hours_have_length_limits(): void
    {
        $this->storePlace([
            'phone' => Str::repeat('1', 51),
            'opening_hours' => Str::repeat('M', 256),
        ])->assertSessionHasErrors(['phone', 'opening_hours']);

        $this->assertDatabaseCount('places', 0);
    }

    public function test_map_page_shares_place_details(): void
    {
        $place = Place::factory()->withDetails()->create();
        $this->user->favoritePlaces()->attach($place->id, ['created_at' => now()]);

        $this->actingAs($this->user)
            ->get('/interactive-map')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('InteractiveMap')
                ->where('places.0.image', $place->image)
                ->where('places.0.description', $place->description)
                ->where('places.0.openingHours', $place->opening_hours)
                ->where('places.0.website', $place->website)
                ->where('places.0.phone', $place->phone)
                ->where('places.0.wikiUrl', $place->wiki_url));
    }

    public function test_favorites_page_shares_place_details(): void
    {
        $place = Place::factory()->withDetails()->create();
        $this->user->favoritePlaces()->attach($place->id, ['created_at' => now()]);

        $this->actingAs($this->user)
            ->get('/favorites')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Favorites/Index')
                ->where('places.0.image', $place->image)
                ->where('places.0.description', $place->description)
                ->where('places.0.wikiUrl', $place->wiki_url));
    }

    public function test_trip_page_shares_place_details(): void
    {
        $trip = Trip::factory()->for($this->user)->create();
        $place = Place::factory()->withDetails()->create();
        $trip->days()->first()->addPlace($place);

        $this->actingAs($this->user)
            ->get("/my-trips/{$trip->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trips/Show')
                ->where('days.0.places.0.image', $place->image)
                ->where('days.0.places.0.wikiUrl', $place->wiki_url));
    }
}
