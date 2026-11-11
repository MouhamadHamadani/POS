<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finish the product_variants scaffold: a variant is a second barcode under a product, with its own label and
 * its own stock count, at the product's price.
 *
 *  - product_variants gets a label (en / ar), old_barcode / old_sku and soft deletes, mirroring what
 *    products got in 2026_09_24_000002: deleting frees the unique barcode/sku and remembers it in old_*.
 *  - inventory_adjustments.product_variant_id says which variant a movement was for (null = the main item).
 *    RESTRICT, because a variant that ever moved stock is soft-deleted, never hard-deleted.
 *  - Backfill: variants of products that were already soft-deleted still hold their codes. Release them the
 *    way ProductController::destroy does now. Idempotent, and unable to throw (it runs on every launch).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('product_variants', 'label')) {
                $table->string('label')->nullable();
            }
            if (! Schema::hasColumn('product_variants', 'label_ar')) {
                $table->string('label_ar')->nullable();
            }
            if (! Schema::hasColumn('product_variants', 'old_barcode')) {
                $table->string('old_barcode')->nullable();
            }
            if (! Schema::hasColumn('product_variants', 'old_sku')) {
                $table->string('old_sku')->nullable();
            }
            if (! Schema::hasColumn('product_variants', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        if (Schema::hasTable('inventory_adjustments') && ! Schema::hasColumn('inventory_adjustments', 'product_variant_id')) {
            Schema::table('inventory_adjustments', function (Blueprint $table) {
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
                $table->index(['product_variant_id', 'created_at']);
            });
        }

        $this->releaseCodesOfDeletedProducts();
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_adjustments') && Schema::hasColumn('inventory_adjustments', 'product_variant_id')) {
            Schema::table('inventory_adjustments', function (Blueprint $table) {
                $table->dropIndex(['product_variant_id', 'created_at']);
                $table->dropConstrainedForeignId('product_variant_id');
            });
        }

        if (Schema::hasTable('product_variants')) {
            Schema::table('product_variants', function (Blueprint $table) {
                $table->dropSoftDeletes();
                $table->dropColumn(['label', 'label_ar', 'old_barcode', 'old_sku']);
            });
        }
    }

    private function releaseCodesOfDeletedProducts(): void
    {
        $rows = DB::table('product_variants')
            ->whereIn('product_id', DB::table('products')->whereNotNull('deleted_at')->select('id'))
            ->where(fn ($q) => $q->whereNotNull('barcode')->orWhereNotNull('sku'))
            ->get(['id', 'barcode', 'sku']);

        foreach ($rows as $row) {
            DB::table('product_variants')->where('id', $row->id)->update([
                'old_barcode' => $row->barcode,
                'old_sku' => $row->sku,
                'barcode' => null,
                'sku' => null,
                'deleted_at' => now(),
            ]);
        }
    }
};
