<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\CityCover;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Photos for places saved before photos existed (or when Wikimedia did not answer at that moment).
 * Run once: php artisan places:photos. New places get a photo right away on the map (PlaceFormOverlay).
 *
 * Wikipedia and Wikidata are searched by the place's name, then Commons by its coordinates (see CityCover);
 * a Wikidata object must lie next to the place, so a common name does not bring a photo from another city.
 * Disambiguation pages and SVG emblems are skipped.
 */
#[Signature('places:photos')]
#[Description('Find a Wikimedia photo for every place that has no photo yet')]
class FetchPlacePhotos extends Command
{
    /**
     * Same width as thumbnails from the map form: the photo in the day plan is small.
     */
    public const Width = 330;

    public function handle(CityCover $wikimedia): int
    {
        $places = Place::query()->whereNull('image')->get();
        $found = 0;

        foreach ($places as $place) {
            $image = $wikimedia->find($place->name, self::Width, $place->latitude, $place->longitude);

            if ($image !== null) {
                $place->update(['image' => $image]);
                $found++;
            }

            $this->line("{$place->name}: ".($image ?? 'не найдено'));
        }

        $this->info("Фото найдены для {$found} из {$places->count()} мест.");

        return self::SUCCESS;
    }
}
