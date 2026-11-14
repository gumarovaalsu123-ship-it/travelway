<?php

namespace App\Models;

use Database\Factories\PackingListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of things to pack for a trip: "Documents", "Clothes"...
 */
#[Fillable(['trip_id', 'title', 'position'])]
class PackingList extends Model
{
    /** @use HasFactory<PackingListFactory> */
    use HasFactory;

    /**
     * The "standard set" button: group title => things in it.
     * Only things needed on any trip: sun cream and a hat come from the summer hint,
     * a socket adapter is not needed for trips around Russia.
     *
     * @var array<string, array<int, string>>
     */
    public const Defaults = [
        'Документы и деньги' => ['Паспорт', 'Билеты', 'Медицинская страховка', 'Банковская карта', 'Наличные'],
        'Одежда и обувь' => ['Удобная обувь', 'Футболки', 'Тёплая кофта', 'Нижнее бельё и носки'],
        'Техника' => ['Телефон и зарядка', 'Пауэрбанк', 'Наушники'],
        'Здоровье и мелочи' => ['Аптечка', 'Зубная щётка и паста', 'Солнечные очки'],
    ];

    /**
     * The "Might come in handy" block: rule => why it is shown, the group to add to, the things.
     * Each rule is checked on its own (see matchesHintRule()), nothing is added without the user.
     *
     * @var array<string, array{reason: string, group: string, items: array<int, string>}>
     */
    public const Hints = [
        'long_ride' => ['reason' => 'Дорога дольше 4 часов', 'group' => 'В дорогу', 'items' => ['Подушка для шеи']],
        'train' => ['reason' => 'Едете на поезде', 'group' => 'В дорогу', 'items' => ['Тапочки', 'Кружка']],
        'own_car' => ['reason' => 'Едете на своей машине', 'group' => 'Для машины', 'items' => ['Водительские права', 'СТС', 'Полис ОСАГО', 'Зарядка в прикуриватель']],
        'hostel' => ['reason' => 'Живёте в хостеле', 'group' => 'Здоровье и мелочи', 'items' => ['Полотенце', 'Беруши']],
        'long_trip' => ['reason' => 'Поездка дольше 7 дней', 'group' => 'Здоровье и мелочи', 'items' => ['Средство для стирки']],
        'winter' => ['reason' => 'Поездка зимой', 'group' => 'По погоде', 'items' => ['Шапка', 'Перчатки', 'Тёплая куртка', 'Термобельё']],
        'spring_autumn' => ['reason' => 'Поездка весной или осенью', 'group' => 'По погоде', 'items' => ['Зонт', 'Непромокаемая обувь']],
        'summer' => ['reason' => 'Поездка летом', 'group' => 'По погоде', 'items' => ['Солнцезащитный крем', 'Головной убор', 'Бутылка для воды']],
    ];

    /**
     * Transport type of the "own car" rule (a key of Transport::Types).
     */
    public const OwnCarType = 'car';

    /**
     * Months of each season rule.
     *
     * @var array<string, array<int, int>>
     */
    protected const SeasonMonths = [
        'winter' => [12, 1, 2],
        'spring_autumn' => [3, 4, 5, 9, 10, 11],
        'summer' => [6, 7, 8],
    ];

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return HasMany<PackingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PackingItem::class)->orderBy('position');
    }

    /**
     * The trip group with this title; a new group is created at the end of the list if there is none.
     */
    public static function firstOrCreateFor(Trip $trip, string $title): self
    {
        return $trip->packingLists()->firstOrCreate(
            ['title' => $title],
            ['position' => (int) $trip->packingLists()->max('position') + 1],
        );
    }

    /**
     * Hints for the "Might come in handy" block. Things the trip already has are left out
     * (case-insensitive), a thing suggested by two rules is shown once — by the first rule.
     *
     * @return array<int, array{rule: string, reason: string, items: array<int, string>}>
     */
    public static function hintsFor(Trip $trip): array
    {
        $taken = $trip->packingItems()->pluck('name')->map(fn (string $name) => self::normalize($name))->all();
        $hints = [];

        foreach (self::Hints as $rule => $hint) {
            if (! self::matchesHintRule($rule, $trip)) {
                continue;
            }

            $items = [];

            foreach ($hint['items'] as $name) {
                if (! in_array(self::normalize($name), $taken, true)) {
                    $items[] = $name;
                    $taken[] = self::normalize($name);
                }
            }

            if ($items !== []) {
                $hints[] = ['rule' => $rule, 'reason' => $hint['reason'], 'items' => $items];
            }
        }

        return $hints;
    }

    /**
     * Whether one hint rule works for the trip. Rules are independent of each other.
     */
    public static function matchesHintRule(string $rule, Trip $trip): bool
    {
        return match ($rule) {
            // Длительность считаем, только когда известны и отправление, и прибытие.
            'long_ride' => $trip->transports->contains(fn (Transport $ride) => $ride->departure_at && $ride->arrival_at
                && $ride->departure_at->diffInMinutes($ride->arrival_at) > 4 * 60),
            'train' => $trip->transports->contains('type', 'train'),
            'own_car' => $trip->transports->contains('type', self::OwnCarType),
            // Типа жилья в базе пока нет (его добавит этап 1), поэтому хостел узнаём по названию.
            'hostel' => $trip->accommodations->contains(fn (Accommodation $stay) => str_contains(self::normalize($stay->name), 'хостел')
                || str_contains(self::normalize($stay->name), 'hostel')),
            'long_trip' => $trip->days_count > 7,
            'winter', 'spring_autumn', 'summer' => array_intersect(self::tripMonths($trip), self::SeasonMonths[$rule]) !== [],
            default => false,
        };
    }

    /**
     * Months (1–12) that the trip days fall into; empty for a draft without dates.
     *
     * @return array<int, int>
     */
    protected static function tripMonths(Trip $trip): array
    {
        if (! $trip->hasDates()) {
            return [];
        }

        $months = [];

        for ($date = $trip->start_date->copy(); $date->lte($trip->end_date); $date->addDay()) {
            $months[$date->month] = $date->month;
        }

        return array_values($months);
    }

    protected static function normalize(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * Add a thing to the end of the group.
     */
    public function addItem(string $name, ?string $note = null): PackingItem
    {
        return $this->items()->create([
            'name' => $name,
            'note' => $note,
            'position' => (int) $this->items()->max('position') + 1,
        ]);
    }
}
