<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `itemized` POs are today's: product lines, received into stock. An `amount_only` PO has no
 * lines, only the amount owed (stored as subtotal_usd = total_usd); confirming it adds that
 * amount to the supplier balance. Existing rows are itemized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('type', 20)->default('itemized');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
