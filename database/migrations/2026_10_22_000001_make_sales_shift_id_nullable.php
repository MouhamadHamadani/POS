<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Selling with shifts switched off (BUG-032).
 *
 * `sales.shift_id` was NOT NULL because every sale was rung up inside a shift. With
 * Settings > POS > "Require open shift to sell" off there is no shift to attach, so the
 * column has to allow NULL. A shift-less sale is legal only while that setting is off
 * (SaleService enforces it); the foreign key and the index stay exactly as they were.
 *
 * SQLite cannot drop NOT NULL in place: Laravel rebuilds the table behind change(),
 * keeping the indexes and foreign keys it reads from the live schema. Verified on a copy
 * of a populated database (row count, PRAGMA foreign_key_check, index and FK lists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->change();
        });
    }

    /**
     * Refuses while shift-less sales exist: putting NOT NULL back would either fail or
     * force us to invent a shift for sales that never had one. Re-attach or remove them first.
     */
    public function down(): void
    {
        if (DB::table('sales')->whereNull('shift_id')->exists()) {
            throw new RuntimeException('Cannot restore NOT NULL on sales.shift_id: shift-less sales exist.');
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable(false)->change();
        });
    }
};
