<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cover photo of a trip: the main picture of the city's Wikipedia article (REST "page summary", no key needed).
 * Docs: https://en.wikipedia.org/api/rest_v1/#/Page%20content/get_page_summary__title_
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
     * Wikipedia usually answers in a fraction of a second; if it does not — the trip is saved without a cover.
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
     * URL of a good photo of the city, or null when nothing was found or Wikipedia did not answer.
     *
     * @param  string  $destination  as the user typed it, for example "Казань" or "Казань, Россия"
     */
    public function find(string $destination): ?string
    {
        // «Казань, Россия» → «Казань»: статья называется по городу.
        $city = trim(Str::before($destination, ','));

        if ($city === '') {
            return null;
        }

        foreach (self::Languages as $language) {
            $image = $this->fromWikipedia($language, $city);

            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    protected function fromWikipedia(string $language, string $city): ?string
    {
        try {
            $response = Http::timeout(self::TimeoutSeconds)
                ->withUserAgent('Travelway (student project)')
                ->acceptJson()
                ->get("https://{$language}.wikipedia.org/api/rest_v1/page/summary/".rawurlencode($city));
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

        $image = ($original['width'] ?? 0) > self::Width && is_string($thumbnail)
            // Миниатюра выглядит как «.../thumb/a/ab/Kazan.jpg/320px-Kazan.jpg» — меняем ширину на нужную.
            ? preg_replace('#/\d+px-#', '/'.self::Width.'px-', $thumbnail)
            : $original['source'];

        // Метки ?utm_source=… Википедия добавляет для статистики — для показа картинки они не нужны.
        $image = Str::before($image, '?');

        return preg_match(self::AllowedImage, $image) === 1 && strlen($image) <= 255 ? $image : null;
    }
}
