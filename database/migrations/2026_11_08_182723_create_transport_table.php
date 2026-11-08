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
        Schema::create('transport', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            // Вид транспорта — ключ из Transport::Types (plane, train, bus, car, ferry, other).
            $table->string('type', 20);
            $table->string('departure_place', 150);
            $table->string('arrival_place', 150);
            $table->dateTime('departure_at')->nullable();
            $table->dateTime('arrival_at')->nullable();
            // Номер брони, билета или рейса.
            $table->text('booking_info')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport');
    }
};
