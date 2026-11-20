<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Когда администратор поставил публичную поездку в «Примеры маршрутов» на главной (null — не стоит).
     * Дата, а не просто да/нет: на главной показываем последние выбранные.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->timestamp('featured_at')->nullable()->after('public_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('featured_at');
        });
    }
};
