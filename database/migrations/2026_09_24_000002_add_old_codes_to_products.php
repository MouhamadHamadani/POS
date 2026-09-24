<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep a deleted product's last barcode/SKU on the product row itself.
 *
 * Deleting a product nulls `barcode`/`sku` so the codes can be reused
 * (2026_09_24_000001 and ProductController::destroy). That left the old values
 * readable only as JSON inside an AuditLog row. `old_barcode`/`old_sku` put them
 * back where a super-admin can just look at them.
 *
 * Purely historical: no unique index, not in any validation rule, so a new
 * product can still claim a code that a deleted product used to carry.
 *
 * The backfill runs in this same migration so it can never execute against a
 * table without the columns. It reads the delete entries the earlier fix wrote;
 * a product deleted before any of that shipped has nothing to recover and keeps
 * a null `old_barcode` — that is the intended outcome, not a failure.
 */
return new class extends Migration
{
    /** What AuditLog::record() stored in model_type — a literal, since these rows are history. */
    private const PRODUCT_TYPE = 'App\Models\Product';

    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'old_barcode')) {
                $table->string('old_barcode')->nullable()->after('sku');
            }
            if (! Schema::hasColumn('products', 'old_sku')) {
                $table->string('old_sku')->nullable()->after('old_barcode');
            }
        });

        $this->backfillFromAuditLog();
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['old_barcode', 'old_sku']);
        });
    }

    /**
     * Copy the codes forward for products already trashed by the earlier fix.
     *
     * Deliberately unable to throw: this runs on every client launch via
     * NativeAppServiceProvider::bootstrapDatabase, and a migration that dies on
     * one odd audit row would take the whole app down on startup.
     */
    private function backfillFromAuditLog(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasColumn('audit_logs', 'old_values')) {
            return;
        }

        $ids = DB::table('products')
            ->whereNotNull('deleted_at')
            ->whereNull('old_barcode')
            ->whereNull('old_sku')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Ordered ascending so a product deleted more than once (trashed, restored
        // by hand, trashed again) ends up keyed to its most recent delete.
        $logs = DB::table('audit_logs')
            ->where('model_type', self::PRODUCT_TYPE)
            ->where('action', 'delete')
            ->whereIn('model_id', $ids)
            ->orderBy('id')
            ->pluck('old_values', 'model_id');

        foreach ($logs as $productId => $json) {
            $old = json_decode((string) $json, true);

            if (! is_array($old)) {
                continue; // unreadable entry — nothing to recover, leave the row null
            }

            $values = array_filter([
                'old_barcode' => $this->code($old['barcode'] ?? null),
                'old_sku' => $this->code($old['sku'] ?? null),
            ]);

            if ($values !== []) {
                DB::table('products')->where('id', $productId)->update($values);
            }
        }
    }

    /** A usable code, or null — never a placeholder for a value that was never recorded. */
    private function code(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return trim((string) $value) === '' ? null : trim((string) $value);
    }
};
