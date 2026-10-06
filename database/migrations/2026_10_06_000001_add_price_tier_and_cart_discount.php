<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price lists (retail / wholesale / vip) and whole-cart discounts.
 *
 * - sales.price_tier / sale_items.price_tier: which price list the sale was rung
 *   up on. Snapshotted per line so a report can group by it later.
 * - sales.cart_discount_usd: the cart-level slice of discount_amount_usd. The
 *   slice is also allocated into each sale_items.discount_amount_usd (so per-line
 *   VAT and returns keep working); this column only exists so the receipt can
 *   print it separately.
 * - held_sales.price_tier / cart_discount: a held cart must come back on the
 *   price list and with the cart discount it was parked with. These are columns
 *   rather than a wrapper around `cart` because `cart` is a plain list of lines
 *   that the hold list and already-parked holds depend on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('price_tier')->default('retail')->after('customer_id');
            $table->decimal('cart_discount_usd', 12, 4)->default(0)->after('discount_amount_usd');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('price_tier')->default('retail')->after('unit_price_usd');
        });

        Schema::table('held_sales', function (Blueprint $table) {
            $table->string('price_tier')->default('retail')->after('customer_id');
            $table->json('cart_discount')->nullable()->after('cart'); // {type: pct|amount, value: n}
        });
    }

    public function down(): void
    {
        Schema::table('held_sales', function (Blueprint $table) {
            $table->dropColumn(['price_tier', 'cart_discount']);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('price_tier');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['price_tier', 'cart_discount_usd']);
        });
    }
};
