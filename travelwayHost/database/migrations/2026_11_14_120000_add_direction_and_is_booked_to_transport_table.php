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
        Schema::table('transport', function (Blueprint $table) {
            // Направление — ключ из Transport::Directions (to, back, onward).
            // Nullable: у уже сохранённых записей направление неизвестно, и угадывать его опасно
            // (обратный рейс, помеченный «Туда», испортит проверку поездки). Такие записи
            // показываются как «Направление не указано», а новые без направления не сохранить.
            $table->string('direction', 10)->nullable()->after('type');
            // «Билет куплен». Для старых записей — false: человек сам отметит купленное.
            $table->boolean('is_booked')->default(false)->after('booking_info');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transport', function (Blueprint $table) {
            $table->dropColumn(['direction', 'is_booked']);
        });
    }
};
