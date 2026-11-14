<?php

namespace App\Console\Commands;

use App\Models\Trip;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Covers for trips created before covers existed (or when Wikipedia did not answer at that moment).
 * Run once: php artisan trips:covers. New trips get a cover right away in TripController.
 */
#[Signature('trips:covers')]
#[Description('Find a Wikipedia photo of the city for every trip that has no cover yet')]
class FetchTripCovers extends Command
{
    public function handle(): int
    {
        $trips = Trip::query()->whereNull('cover_image')->get();
        $found = 0;

        foreach ($trips as $trip) {
            $trip->refreshCover();

            if ($trip->cover_image !== null) {
                $found++;
            }

            $this->line("{$trip->destination}: ".($trip->cover_image ?? 'не найдено'));
        }

        $this->info("Обложки найдены для {$found} из {$trips->count()} поездок.");

        return self::SUCCESS;
    }
}
