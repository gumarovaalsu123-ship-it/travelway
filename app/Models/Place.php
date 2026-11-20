<?php

namespace App\Models;

use App\Services\PhotoStorage;
use Database\Factories\PlaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'category_id', 'name', 'address', 'latitude', 'longitude',
    'image', 'opening_hours', 'website', 'phone', 'wiki_url',
])]
class Place extends Model
{
    /** @use HasFactory<PlaceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PlaceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PlaceCategory::class, 'category_id');
    }

    /**
     * @return HasMany<TripPlace, $this>
     */
    public function tripPlaces(): HasMany
    {
        return $this->hasMany(TripPlace::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wishlists')->withPivot('created_at');
    }

    /**
     * Сведения о месте из открытых источников — одинаковые для карты, избранного и страницы поездки.
     *
     * @return array{image: ?string, openingHours: ?string, website: ?string, phone: ?string, wikiUrl: ?string}
     */
    public function details(): array
    {
        return [
            'image' => $this->image,
            'openingHours' => $this->opening_hours,
            'website' => $this->website,
            'phone' => $this->phone,
            'wikiUrl' => $this->wiki_url,
        ];
    }

    /**
     * Одно и то же место, отмеченное на карте дважды, почти никогда не попадает в те же координаты:
     * клик ставит точку на пару метров дальше. Поэтому «то же место» — это место пользователя
     * с тем же названием (без учёта регистра) не дальше NearbyMeters от новой точки.
     */
    public const NearbyMeters = 100;

    /**
     * Ищет уже сохранённое место пользователя с тем же названием рядом с точкой.
     * Сначала грубо отбираем базой по квадрату ±0.001° (около 100 м), потом точно меряем расстояние.
     */
    public static function findNearbyOfUser(int $userId, string $name, float $latitude, float $longitude): ?self
    {
        // Градус долготы короче к северу, поэтому квадрат по долготе шире.
        $latitudeDelta = 0.001;
        $longitudeDelta = $latitudeDelta / max(cos(deg2rad($latitude)), 0.01);
        $sameName = mb_strtolower(trim($name));

        return self::query()
            ->where('user_id', $userId)
            ->whereBetween('latitude', [$latitude - $latitudeDelta, $latitude + $latitudeDelta])
            ->whereBetween('longitude', [$longitude - $longitudeDelta, $longitude + $longitudeDelta])
            ->get()
            ->filter(fn (self $place) => mb_strtolower(trim($place->name)) === $sameName)
            ->filter(fn (self $place) => self::distanceInMeters($latitude, $longitude, $place->latitude, $place->longitude) <= self::NearbyMeters)
            ->sortBy(fn (self $place) => self::distanceInMeters($latitude, $longitude, $place->latitude, $place->longitude))
            ->first();
    }

    /**
     * Расстояние между двумя точками по поверхности Земли (формула гаверсинусов).
     */
    public static function distanceInMeters(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadius = 6371000;
        $latitudeDifference = deg2rad($toLatitude - $fromLatitude);
        $longitudeDifference = deg2rad($toLongitude - $fromLongitude);

        $haversine = sin($latitudeDifference / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($longitudeDifference / 2) ** 2;

        return 2 * $earthRadius * asin(min(1, sqrt($haversine)));
    }

    /**
     * Remove the place once it is neither planned in a trip nor favorited by anyone.
     */
    public function deleteIfUnused(): void
    {
        if ($this->tripPlaces()->doesntExist() && $this->favoritedBy()->doesntExist()) {
            $this->delete();
            // Своё загруженное фото больше никому не нужно — удаляем и файл (фото из Википедии просто пропускается).
            app(PhotoStorage::class)->delete($this->image);
        }
    }
}
