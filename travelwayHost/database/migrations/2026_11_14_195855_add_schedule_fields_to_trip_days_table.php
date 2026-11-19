<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расписание дня (этап 6 концепции): во сколько день начинается и дорога обратно к жилью.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trip_days', function (Blueprint $table) {
            // Начало дня. null — по умолчанию 10:00 (или время прибытия «Туда» в первый день).
            $table->time('start_time')->nullable()->after('title');
            // Последний отрезок «место → жильё» из последнего расчёта: минуты и способ.
            // У отрезков между местами это хранится в trip_places, а у этого отрезка места нет.
            $table->unsignedSmallInteger('return_minutes')->nullable()->after('start_time');
            $table->string('return_mode', 10)->nullable()->after('return_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_days', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'return_minutes', 'return_mode']);
        });
    }
};
