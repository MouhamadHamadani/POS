<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release the barcodes and SKUs still reserved by already-deleted products.
 *
 * `products.barcode` and `products.sku` are UNIQUE, and a unique index knows
 * nothing about `deleted_at` — so a soft-deleted product went on holding its
 * codes forever. A client who deleted a product (gone from every list they can
 * see) then tried to create a replacement on the same barcode was told it was
 * "already used for a product" they could no longer find.
 *
 * ProductController::destroy() now clears both columns before soft-deleting, but
 * that only helps future deletes. This backfills installs where it already
 * happened; migrations run on every app launch (see
 * NativeAppServiceProvider::bootstrapDatabase), so this is what actually
 * unsticks a barcode on a machine already in the field.
 *
 * Only rows with `deleted_at` set are touched — live products are untouched, and
 * sale_items keeps its own product_name/product_sku snapshots, so receipts and
 * reports for sales of a deleted product are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        foreach (['deleted_at', 'barcode', 'sku'] as $column) {
            if (! Schema::hasColumn('products', $column)) {
                return;
            }
        }

        DB::table('products')
            ->whereNotNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('barcode')->orWhereNotNull('sku'))
            ->update(['barcode' => null, 'sku' => null]);
    }

    public function down(): void
    {
        // Not reversible: the freed codes only survive in the delete's audit-log
        // entry, and putting them back could now collide with a live product.
    }
};
