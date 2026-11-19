<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Photo of a city (trip cover) or of a place, from open Wikimedia projects. No keys are needed.
 *
 * Sources are tried one by one until one of them gives a photo:
 * 1. Wikipedia — the main picture of the article (REST "page summary"), Russian first, then English.
 *    Docs: https://en.wikipedia.org/api/rest_v1/#/Page%20content/get_page_summary__title_
 * 2. Wikidata — the "image" property (P18) of an object found by its name. Many places have no
 *    Wikipedia article but do have a Wikidata entry with a photo.
 *    Docs: https://www.wikidata.org/w/api.php?action=help&modules=wbsearchentities
 * Steps 3 and 4 run only for places, when their coordinates are known:
 * 3. Wikipedia full-text search — for an article named differently from the place, if it lies next to it.
 * 4. Wikimedia Commons — a photo taken next to the place.
 *    Docs: https://www.mediawiki.org/wiki/Extension:GeoData#list=geosearch
 *
 * All of them keep their pictures on upload.wikimedia.org, so the result passes the same AllowedImage check.
 */
class CityCover
{
    /**
     * Russian article first, English if there is no Russian one or it has no picture.
     *
     * @var array<int, string>
     */
    public const Languages = ['ru', 'en'];

    /**
     * Wikimedia usually answers in a fraction of a second; if it does not — the trip is saved without a cover.
     */
    public const TimeoutSeconds = 4;

    /**
     * Width of the cover. The original photo can be 6000 px and 10 MB, so a bigger one is taken as a thumbnail of this width.
     * Wikimedia serves thumbnails only in standard widths (330, 500, 960, 1280, 1920…):
     * any other width, for example 1600, returns an error page instead of a picture.
     */
    public const Width = 1280;

    /**
     * Only pictures from Wikimedia servers are accepted (the same rule as WIKIMEDIA_IMAGE in utils/geocoding.js).
     */
    public const AllowedImage = '#^https://(upload|thumb)\.wikimedia\.org/#';

    /**
     * How many Wikidata objects with the same name are checked («Кремль» — there are kremlins in many cities).
     */
    public const WikidataCandidates = 5;

    /**
     * A Wikidata object counts as «this place» only when it lies this close to the place's coordinates:
     * 0.01° is about 1 km (a simple square instead of the exact distance on a sphere — precise enough here).
     */
    public const MaxDistanceDegrees = 0.01;

    /**
     * Commons photos are looked for within this radius around the place, in meters.
     */
    public const NearbyRadiusMeters = 100;

    /**
     * Raster formats only: an SVG is an emblem or a scheme, not a photo.
     *
     * @var array<int, string>
     */
    public const PhotoTypes = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * URL of a good photo, or null when no source found anything or Wikimedia did not answer.
     *
     * @param  string  $destination  as the user typed it, for example "Казань" or "Казань, Россия"
     * @param  int  $width  one of the standard Wikimedia thumbnail widths (see Width)
     * @param  float|null  $latitude  coordinates of a place (command places:photos); null for a city
     */
    public function find(string $destination, int $width = self::Width, ?float $latitude = null, ?float $longitude = null): ?string
    {
        // «Казань, Россия» → «Казань»: статья называется по городу.
        $name = trim(Str::before($destination, ','));

        if ($name === '') {
            return null;
        }

        foreach (self::Languages as $language) {
            $image = $this->fromWikipedia($language, $name, $width);

            if ($image !== null) {
                return $image;
            }
        }

        $image = $this->fromWikidata($name, $width, $latitude, $longitude);

        if ($image === null && $latitude !== null && $longitude !== null) {
            $image = $this->fromWikipediaSearch($name, $width, $latitude, $longitude)
                ?? $this->fromCommonsNearby($latitude, $longitude, $width);
        }

        return $image;
    }

    protected function fromWikipedia(string $language, string $name, int $width): ?string
    {
        try {
            $response = $this->http()->get("https://{$language}.wikipedia.org/api/rest_v1/page/summary/".rawurlencode($name));
        } catch (ConnectionException) {
            return null;
        }

        // Страница неоднозначности («Пушкин» — город, поэт...) — не про город, картинки у неё нет.
        if ($response->failed() || $response->json('type') === 'disambiguation') {
            return null;
        }

        $original = $response->json('originalimage');
        $thumbnail = $response->json('thumbnail.source');

        if (! is_array($original) || ! is_string($original['source'] ?? null)) {
            return null;
        }

        // Герб или флаг в формате SVG — плохая обложка, такую картинку пропускаем.
        if (Str::endsWith(Str::lower($original['source']), '.svg')) {
            return null;
        }

        $image = ($original['width'] ?? 0) > $width && is_string($thumbnail)
            // Миниатюра выглядит как «.../thumb/a/ab/Kazan.jpg/320px-Kazan.jpg» — меняем ширину на нужную.
            ? preg_replace('#/\d+px-#', '/'.$width.'px-', $thumbnail)
            : $original['source'];

        return $this->acceptable($image);
    }

    /**
     * Full-text search in Russian Wikipedia: the article can be named differently from the place
     * («Центр семьи Казан» → «Казан (центр семьи)»), so the exact-title request above misses it.
     *
     * Search finds many unrelated articles too, so only an article whose own coordinates lie next
     * to the place is accepted — that is why this step runs only for places, not for cities.
     */
    protected function fromWikipediaSearch(string $name, int $width, float $latitude, float $longitude): ?string
    {
        try {
            // Одним запросом: найденные статьи + их главная картинка (pageimages) и координаты (coordinates).
            $response = $this->http()->get('https://ru.wikipedia.org/w/api.php', [
                'action' => 'query',
                'generator' => 'search',
                'gsrsearch' => $name,
                'gsrlimit' => self::WikidataCandidates,
                'prop' => 'pageimages|coordinates',
                'piprop' => 'name',
                'format' => 'json',
                'formatversion' => 2,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        // «index» — место статьи в выдаче поиска (1 — самая подходящая).
        $pages = collect($response->json('query.pages', []))->sortBy('index');

        foreach ($pages as $page) {
            $coordinates = $page['coordinates'][0] ?? null;
            $file = $page['pageimage'] ?? null;

            if (! is_array($coordinates) || ! is_string($file)) {
                continue;
            }

            if (abs(($coordinates['lat'] ?? 0) - $latitude) <= self::MaxDistanceDegrees
                && abs(($coordinates['lon'] ?? 0) - $longitude) <= self::MaxDistanceDegrees) {
                // Дальше как с Wikidata: миниатюра нужной ширины, SVG отсеиваются. Спрашиваем русскую Википедию, а не Commons:
                // фото современных зданий часто загружены только в неё (в России нет «свободы панорамы»), а её API видит оба хранилища.
                return $this->fileThumbnail($file, $width, 'ru.wikipedia.org');
            }
        }

        return null;
    }

    /**
     * Photo (property P18) of the first Wikidata object with this name that is a point on the map.
     *
     * Objects without coordinates (P625) are skipped: for «Пушкин» Wikidata first finds the poet, not the town.
     * When the place's own coordinates are known, the object must also lie next to it — otherwise
     * «Дом чая» in Kazan could get a photo of a tea house in another city.
     */
    protected function fromWikidata(string $name, int $width, ?float $latitude, ?float $longitude): ?string
    {
        try {
            // 1) Поиск по названию: только номера объектов (Q…), по релевантности.
            $search = $this->http()->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbsearchentities',
                'search' => $name,
                'language' => 'ru',
                'uselang' => 'ru',
                'type' => 'item',
                'limit' => self::WikidataCandidates,
                'format' => 'json',
            ]);

            $ids = collect($search->json('search', []))->pluck('id')->filter(fn ($id) => is_string($id))->values();

            if ($search->failed() || $ids->isEmpty()) {
                return null;
            }

            // 2) Свойства найденных объектов одним запросом: координаты (P625) и фото (P18).
            $entities = $this->http()->get('https://www.wikidata.org/w/api.php', [
                'action' => 'wbgetentities',
                'ids' => $ids->implode('|'),
                'props' => 'claims',
                'format' => 'json',
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if ($entities->failed()) {
            return null;
        }

        // Идём в порядке поиска: первый подходящий объект — самый релевантный.
        foreach ($ids as $id) {
            $claims = $entities->json("entities.{$id}.claims", []);
            $coordinates = $claims['P625'][0]['mainsnak']['datavalue']['value'] ?? null;
            $file = $claims['P18'][0]['mainsnak']['datavalue']['value'] ?? null;

            if (! is_array($coordinates) || ! is_string($file)) {
                continue;
            }

            if ($latitude !== null && $longitude !== null
                && (abs(($coordinates['latitude'] ?? 0) - $latitude) > self::MaxDistanceDegrees
                    || abs(($coordinates['longitude'] ?? 0) - $longitude) > self::MaxDistanceDegrees)) {
                continue;
            }

            return $this->fileThumbnail($file, $width, 'commons.wikimedia.org');
        }

        return null;
    }

    /**
     * Wikidata and Wikipedia search give only the file name («Kazan Kremlin.jpg»); the wiki API turns it into a thumbnail of the right width.
     *
     * @param  string  $host  commons.wikimedia.org, or a Wikipedia when the file may be stored only there
     */
    protected function fileThumbnail(string $file, int $width, string $host): ?string
    {
        try {
            $response = $this->http()->get("https://{$host}/w/api.php", [
                'action' => 'query',
                'titles' => 'File:'.$file,
                ...$this->imageInfoParameters($width),
            ]);
        } catch (ConnectionException) {
            return null;
        }

        return $response->failed() ? null : $this->imageFromInfo($response->json('query.pages.0.imageinfo.0'));
    }

    /**
     * The closest photo from Commons that was taken within NearbyRadiusMeters of the place.
     */
    protected function fromCommonsNearby(float $latitude, float $longitude, int $width): ?string
    {
        try {
            $response = $this->http()->get('https://commons.wikimedia.org/w/api.php', [
                'action' => 'query',
                'generator' => 'geosearch',
                'ggscoord' => $latitude.'|'.$longitude,
                'ggsradius' => self::NearbyRadiusMeters,
                'ggsnamespace' => 6, // пространство «Файл:» — только фотографии, без статей
                'ggslimit' => 10,
                ...$this->imageInfoParameters($width),
            ]);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        // Страницы приходят вперемешку; «index» — их место в выдаче (0 — ближайшая к точке).
        $pages = collect($response->json('query.pages', []))->sortBy('index');

        foreach ($pages as $page) {
            $image = $this->imageFromInfo($page['imageinfo'][0] ?? null);

            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    /**
     * Ask Commons for the file type and a ready thumbnail URL of the given width.
     * If the original is narrower, Commons returns the original instead — no broken link.
     *
     * @return array<string, int|string>
     */
    protected function imageInfoParameters(int $width): array
    {
        return [
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime',
            'iiurlwidth' => $width,
            'format' => 'json',
            'formatversion' => 2,
        ];
    }

    /**
     * @param  mixed  $info  one item of «imageinfo» from the Commons API: {mime, url, thumburl}
     */
    protected function imageFromInfo(mixed $info): ?string
    {
        if (! is_array($info) || ! in_array($info['mime'] ?? null, self::PhotoTypes, true)) {
            return null;
        }

        $image = $info['thumburl'] ?? $info['url'] ?? null;

        return is_string($image) ? $this->acceptable($image) : null;
    }

    /**
     * The final check for every source: a Wikimedia picture that fits into the image column.
     */
    protected function acceptable(string $image): ?string
    {
        // Метки ?utm_source=… Википедия добавляет для статистики — для показа картинки они не нужны.
        $image = Str::before($image, '?');

        return preg_match(self::AllowedImage, $image) === 1 && strlen($image) <= 255 ? $image : null;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(self::TimeoutSeconds)
            ->withUserAgent('Travelway (student project)')
            ->acceptJson();
    }
}
