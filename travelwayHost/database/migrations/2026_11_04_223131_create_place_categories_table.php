<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('place_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestamps();
        });

        $categories = [
            'cafe' => 'Кофейня',
            'restaurant' => 'Ресторан',
            'museum' => 'Музей',
            'attraction' => 'Достопримечательность',
            'viewpoint' => 'Смотровая площадка',
            'park' => 'Парк',
            'hotel' => 'Отель',
            'shop' => 'Магазин',
            'transport' => 'Транспорт',
            'other' => 'Другое',
        ];

        DB::table('place_categories')->insert(
            collect($categories)
                ->map(fn (string $name, string $slug) => [
                    'slug' => $slug,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all()
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('place_categories');
    }
};
