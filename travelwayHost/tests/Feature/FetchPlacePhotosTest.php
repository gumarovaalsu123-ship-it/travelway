<?php

namespace Tests\Feature;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Command places:photos: photos from Wikipedia, Wikidata and Commons for old places. The real Wikimedia is never called.
 */
class FetchPlacePhotosTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_finds_small_photos_only_for_places_without_one(): void
    {
        Http::preventStrayRequests();
        Http::fake(['ru.wikipedia.org/*' => Http::response([
            'type' => 'standard',
            'originalimage' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Kremlin.jpg', 'width' => 4000, 'height' => 3000],
            'thumbnail' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Kremlin.jpg/320px-Kremlin.jpg?utm_source=x', 'width' => 320, 'height' => 240],
        ])]);
        $withoutPhoto = Place::factory()->create(['name' => 'Казанский Кремль', 'image' => null]);
        $withPhoto = Place::factory()->withDetails()->create();

        $this->artisan('places:photos')->assertSuccessful();

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Kremlin.jpg/330px-Kremlin.jpg',
            $withoutPhoto->fresh()->image,
        );
        $this->assertSame($withPhoto->image, $withPhoto->fresh()->image);
        Http::assertSentCount(1);
    }

    public function test_place_stays_without_photo_when_no_source_has_one(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*.wikipedia.org/*' => Http::response([], 404),
            'www.wikidata.org/*' => Http::response(['search' => []]),
            'commons.wikimedia.org/*' => Http::response(['batchcomplete' => true]),
        ]);
        $place = Place::factory()->create(['name' => 'Дом чая', 'image' => null]);

        $this->artisan('places:photos')->assertSuccessful();

        $this->assertNull($place->fresh()->image);
    }

    public function test_article_with_another_title_is_found_by_search_next_to_the_place(): void
    {
        $photo = 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/c1/Kazan_family_center.jpg/330px-Kazan_family_center.jpg';
        Http::preventStrayRequests();
        Http::fake([
            // Статьи «Центр семьи Казан» нет — она называется «Казан (центр семьи)».
            '*.wikipedia.org/api/rest_v1/*' => Http::response([], 404),
            'www.wikidata.org/*' => Http::response(['search' => []]),
            'ru.wikipedia.org/w/api.php*' => Http::sequence()
                ->push(['query' => ['pages' => [
                    ['index' => 2, 'title' => 'Казан (центр семьи)', 'pageimage' => 'Kazan_family_center.jpg', 'coordinates' => [['lat' => 55.8125, 'lon' => 49.1103]]],
                    // Первая в выдаче статья — про другое место, далеко от нашего: пропускаем.
                    ['index' => 1, 'title' => 'Казань', 'pageimage' => 'Kazan_city.jpg', 'coordinates' => [['lat' => 55.7963, 'lon' => 49.1088]]],
                ]]])
                // Файл спрашиваем у той же Википедии: фото может быть загружено только в неё, а не в Commons.
                ->push(['query' => ['pages' => [
                    ['imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => $photo]]],
                ]]]),
        ]);
        $place = Place::factory()->create(['name' => 'Центр семьи Казан', 'image' => null, 'latitude' => 55.8124969, 'longitude' => 49.1102454]);

        $this->artisan('places:photos')->assertSuccessful();

        $this->assertSame($photo, $place->fresh()->image);
        Http::assertSent(fn ($request) => ($request['titles'] ?? null) === 'File:Kazan_family_center.jpg');
        Http::assertNotSent(fn ($request) => ($request['titles'] ?? null) === 'File:Kazan_city.jpg');
    }

    public function test_wikidata_object_far_from_the_place_is_skipped_and_nearby_commons_photo_is_taken(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*.wikipedia.org/*' => Http::response([], 404),
            'www.wikidata.org/*' => Http::sequence()
                ->push(['search' => [['id' => 'Q1']]])
                // «Дом чая» в Москве — с фото, но за сотни километров от места в Казани.
                ->push(['entities' => ['Q1' => ['claims' => [
                    'P625' => [['mainsnak' => ['datavalue' => ['value' => ['latitude' => 55.75, 'longitude' => 37.62]]]]],
                    'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Moscow tea house.jpg']]]],
                ]]]]),
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
                ['index' => 2, 'imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/cc/Far.jpg/330px-Far.jpg']]],
                ['index' => 0, 'imageinfo' => [['mime' => 'image/svg+xml', 'thumburl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/dd/Map.svg/330px-Map.svg.png']]],
                ['index' => 1, 'imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/ee/Tea_house.jpg/330px-Tea_house.jpg']]],
            ]]]),
        ]);
        $place = Place::factory()->create(['name' => 'Дом чая', 'image' => null, 'latitude' => 55.7887, 'longitude' => 49.1221]);

        $this->artisan('places:photos')->assertSuccessful();

        // Ближайшая картинка — схема в SVG, поэтому берётся следующая по расстоянию фотография.
        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/thumb/e/ee/Tea_house.jpg/330px-Tea_house.jpg', $place->fresh()->image);
        Http::assertNotSent(fn ($request) => ($request['titles'] ?? null) === 'File:Moscow tea house.jpg');
        Http::assertSent(fn ($request) => ($request['ggscoord'] ?? null) === '55.7887|49.1221' && (int) $request['iiurlwidth'] === 330);
    }
}
