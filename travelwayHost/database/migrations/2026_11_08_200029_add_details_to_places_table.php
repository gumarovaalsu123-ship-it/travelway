<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Информация о месте из открытых источников (OpenStreetMap и Википедия).
 * Ссылка на фото с кириллицей в процентной кодировке легко длиннее 255 символов, поэтому image становится text.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->text('image')->nullable()->change();
            $table->string('opening_hours', 255)->nullable()->after('image');
            $table->string('website', 500)->nullable()->after('opening_hours');
            $table->string('phone', 50)->nullable()->after('website');
            $table->string('wiki_url', 500)->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn(['opening_hours', 'website', 'phone', 'wiki_url']);
            $table->string('image')->nullable()->change();
        });
    }
};
