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
     *
     * @var array<string, array<int, string>>
     */
    public const Defaults = [
        'Документы и деньги' => ['Паспорт', 'Билеты', 'Медицинская страховка', 'Банковская карта', 'Наличные'],
        'Одежда и обувь' => ['Удобная обувь', 'Футболки', 'Тёплая кофта', 'Нижнее бельё и носки', 'Головной убор'],
        'Техника' => ['Телефон и зарядка', 'Пауэрбанк', 'Наушники', 'Переходник для розетки'],
        'Здоровье и мелочи' => ['Аптечка', 'Зубная щётка и паста', 'Солнцезащитный крем', 'Солнечные очки'],
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
