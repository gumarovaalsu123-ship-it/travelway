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
        Schema::table('trips', function (Blueprint $table) {
            // Цепочка городов: поездка, из которой человек приехал сюда переездом «Дальше».
            // unique — у поездки может быть только одна следующая (дальше едут в один город).
            // nullOnDelete — если предыдущую поездку удалили, эта просто становится первой в цепочке.
            $table->foreignId('previous_trip_id')->nullable()->unique()->after('user_id')
                ->constrained('trips')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeign(['previous_trip_id']);
            $table->dropUnique(['previous_trip_id']);
            $table->dropColumn('previous_trip_id');
        });
    }
};
