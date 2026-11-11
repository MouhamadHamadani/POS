<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pack / package / carton selling (client/tobacco, docs/packaging.md).
 *
 * Stock stays in packs. products describes how a pack is grouped and priced; sale_items snapshot which
 * unit a line was sold in and how many packs that is, so a report, a return or a void never has to
 * look the product up again. Every column is guarded: the migration is safe to run twice and on a
 * database that already has some of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'has_package')) {
                $table->boolean('has_package')->default(false);
            }
            if (! Schema::hasColumn('products', 'package_size')) {
                $table->unsignedSmallInteger('package_size')->nullable();
            }
            foreach (['package_price_usd', 'package_wholesale_price_usd', 'package_vip_price_usd',
                'carton_price_usd', 'carton_wholesale_price_usd', 'carton_vip_price_usd'] as $col) {
                if (! Schema::hasColumn('products', $col)) {
                    $table->decimal($col, 12, 4)->nullable();
                }
            }
            if (! Schema::hasColumn('products', 'carton_barcode')) {
                $table->string('carton_barcode')->nullable()->unique();
            }
            if (! Schema::hasColumn('products', 'packages_per_carton')) {
                $table->unsignedSmallInteger('packages_per_carton')->nullable();
            }
            if (! Schema::hasColumn('products', 'sell_carton_at_pos')) {
                $table->boolean('sell_carton_at_pos')->default(false);
            }
        });

        $backfill = ! Schema::hasColumn('sale_items', 'base_qty');

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'sale_unit')) {
                $table->string('sale_unit')->default('pack');
            }
            if (! Schema::hasColumn('sale_items', 'unit_multiplier')) {
                $table->decimal('unit_multiplier', 12, 4)->default(1);
            }
            if (! Schema::hasColumn('sale_items', 'base_qty')) {
                $table->decimal('base_qty', 12, 4)->default(0);
            }
        });

        // Every sale that exists was sold in packs: base_qty is its qty.
        if ($backfill) {
            DB::table('sale_items')->update(['base_qty' => DB::raw('qty')]);
        }
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['sale_unit', 'unit_multiplier', 'base_qty']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['carton_barcode']);
            $table->dropColumn([
                'has_package', 'package_size', 'package_price_usd', 'package_wholesale_price_usd', 'package_vip_price_usd',
                'carton_barcode', 'packages_per_carton', 'sell_carton_at_pos',
                'carton_price_usd', 'carton_wholesale_price_usd', 'carton_vip_price_usd',
            ]);
        });
    }
};
