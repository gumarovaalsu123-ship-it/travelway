<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            // Тип жилья — ключ из Accommodation::Types (hotel, apartment, hostel, friends, other).
            // Уже сохранённое жильё получает «other» («Другое»): это честно — тип не указывали,
            // и ни на какие проверки он не влияет (кроме «У знакомых», которое старым записям не подходит).
            $table->string('type', 20)->default('other')->after('trip_id');
            // «Забронировано». Для старых записей — false: человек сам отметит бронь.
            $table->boolean('is_booked')->default(false)->after('booking_info');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_booked']);
        });
    }
};
