<?php

namespace App\Models;

use Database\Factories\PackingItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing to pack; is_completed means it is already in the suitcase.
 */
#[Fillable(['packing_list_id', 'name', 'note', 'is_completed', 'position'])]
class PackingItem extends Model
{
    /** @use HasFactory<PackingItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PackingList, $this>
     */
    public function packingList(): BelongsTo
    {
        return $this->belongsTo(PackingList::class);
    }
}
