<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->json('opening_denominations')->nullable()->after('opening_cash_lbp');
            $table->json('closing_denominations')->nullable()->after('closing_cash_lbp');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->json('denominations')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['opening_denominations', 'closing_denominations']);
        });
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn('denominations');
        });
    }
};
