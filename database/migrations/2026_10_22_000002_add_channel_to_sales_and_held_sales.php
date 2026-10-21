<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales channel: was this sale rung up at the counter (onsite) or taken as an online order?
 *
 * "Online" means an order the store receives by WhatsApp / Instagram / Facebook / phone and
 * rings up by hand in the POS. It is not a website integration and nothing is imported; the
 * column only lets the store tell the two apart in Sales History and the reports. It has no
 * effect on pricing, stock, tax or loyalty.
 *
 * - sales.channel: onsite|online. Every existing row is a counter sale, hence the default.
 * - sales.channel_source: whatsapp|facebook|instagram|phone|other, only ever set with `online`.
 * - held_sales.*: a parked cart comes back on the channel it was parked with (same reason as
 *   price_tier in 2026_10_06_000001).
 * - (channel, created_at) serves "online sales this week" without scanning the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('channel')->default('onsite')->after('price_tier');
            $table->string('channel_source')->nullable()->after('channel');
            $table->index(['channel', 'created_at']);
        });

        Schema::table('held_sales', function (Blueprint $table) {
            $table->string('channel')->default('onsite')->after('price_tier');
            $table->string('channel_source')->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('held_sales', function (Blueprint $table) {
            $table->dropColumn(['channel', 'channel_source']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['channel', 'created_at']);
            $table->dropColumn(['channel', 'channel_source']);
        });
    }
};
