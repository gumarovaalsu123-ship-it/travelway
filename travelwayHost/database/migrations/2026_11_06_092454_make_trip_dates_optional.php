<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Поездку можно создать без точных дат — только с количеством дней (черновик).
 * Статус больше не хранится: он вычисляется по датам (см. Trip::status()).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->date('start_date')->nullable()->change();
            $table->date('end_date')->nullable()->change();
            $table->unsignedSmallInteger('days_count')->default(1)->after('end_date');
            $table->dropColumn('status');
        });

        Schema::table('trip_days', function (Blueprint $table) {
            $table->date('date')->nullable()->change();
        });

        DB::table('trips')->whereNotNull('start_date')->orderBy('id')->each(function (object $trip) {
            DB::table('trips')->where('id', $trip->id)->update([
                'days_count' => Carbon::parse($trip->start_date)->diffInDays(Carbon::parse($trip->end_date)) + 1,
            ]);
        });
    }

    /**
     * Reverse the migrations. Черновики без дат получают сегодняшние даты, иначе колонки не сделать обязательными.
     */
    public function down(): void
    {
        DB::table('trips')->whereNull('start_date')->update(['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]);
        DB::table('trip_days')->whereNull('date')->update(['date' => now()->toDateString()]);

        Schema::table('trip_days', function (Blueprint $table) {
            $table->date('date')->nullable(false)->change();
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->date('start_date')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
            $table->dropColumn('days_count');
            $table->string('status')->default('planned');
        });
    }
};
