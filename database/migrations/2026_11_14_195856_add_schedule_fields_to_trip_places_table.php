<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расписание дня (этап 6 концепции): сколько быть на месте и как добираться до него.
 * Отрезок пути хранится у места, в которое он ведёт (от предыдущей точки до этого места).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trip_places', function (Blueprint $table) {
            // Сколько минут провести на месте. null — по категории (TripPlace::DefaultStayMinutes).
            $table->unsignedSmallInteger('stay_minutes')->nullable()->after('end_time');
            // Способ, выбранный человеком (walk / transit / car / taxi). null — выбирает расчёт.
            $table->string('travel_mode', 10)->nullable()->after('stay_minutes');
            // Результат последнего расчёта: минуты в пути и способ, который получился.
            // Способ храним отдельно от travel_mode, иначе после расчёта нельзя отличить выбор человека от автовыбора.
            $table->unsignedSmallInteger('travel_minutes')->nullable()->after('travel_mode');
            $table->string('calculated_mode', 10)->nullable()->after('travel_minutes');
            // 🔒 Время поставлено вручную — расчёт его не двигает.
            $table->boolean('time_locked')->default(false)->after('calculated_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_places', function (Blueprint $table) {
            $table->dropColumn(['stay_minutes', 'travel_mode', 'travel_minutes', 'calculated_mode', 'time_locked']);
        });
    }
};
