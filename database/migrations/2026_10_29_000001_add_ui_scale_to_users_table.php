<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface size, chosen by each user for themselves: the root font size of the app and POS
 * layouts as a percentage (90 | 100 | 110 | 125, see User::UI_SCALES). NULL is 100. It is a
 * per-user preference and deliberately not a setting: nobody can set it for anyone else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('ui_scale')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui_scale');
        });
    }
};
