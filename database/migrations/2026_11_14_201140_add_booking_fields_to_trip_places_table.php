<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Билет или бронь для места плана (этап 3 концепции): «нужен билет/бронь» и «куплено».
 * Место с needs_booking и без is_booked попадает в дела вкладки «Заранее».
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trip_places', function (Blueprint $table) {
            $table->boolean('needs_booking')->default(false)->after('time_locked');
            $table->boolean('is_booked')->default(false)->after('needs_booking');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_places', function (Blueprint $table) {
            $table->dropColumn(['needs_booking', 'is_booked']);
        });
    }
};
