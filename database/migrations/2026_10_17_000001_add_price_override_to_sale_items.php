<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier price override (per line, current sale only).
 *
 * - original_unit_price_usd: the price-list price at the time of sale. unit_price_usd
 *   stays the price actually charged, so a line was overridden when the two differ.
 *   Rows from before this migration were never overridden: original = charged.
 * - price_overridden_by: who set the price (the cashier, or the manager who did it themselves).
 * - price_override_approved_by: the manager who authorised a cut beyond the cashier's limit.
 * - price_override_reason: free text the cashier typed.
 * - price_approval_ref: nonce of the manager's approval that was spent on this line, unique
 *   so one approval cannot be used on two sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('original_unit_price_usd', 12, 4)->nullable()->after('unit_price_usd');
            $table->foreignId('price_overridden_by')->nullable()->after('original_unit_price_usd')->constrained('users')->nullOnDelete();
            $table->foreignId('price_override_approved_by')->nullable()->after('price_overridden_by')->constrained('users')->nullOnDelete();
            $table->string('price_override_reason', 255)->nullable()->after('price_override_approved_by');
            $table->string('price_approval_ref', 64)->nullable()->unique()->after('price_override_reason');
        });

        DB::table('sale_items')->update(['original_unit_price_usd' => DB::raw('unit_price_usd')]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropUnique(['price_approval_ref']);
            $table->dropForeign(['price_overridden_by']);
            $table->dropForeign(['price_override_approved_by']);
            $table->dropColumn([
                'original_unit_price_usd', 'price_overridden_by', 'price_override_approved_by',
                'price_override_reason', 'price_approval_ref',
            ]);
        });
    }
};
