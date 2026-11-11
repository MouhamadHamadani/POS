<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Debt book: the columns the sale and the customer carry. The ledger itself is the next migration.
 *
 * - sales.due_date: the one date a credit sale (amount_credit_usd > 0) is due. Null for every other sale.
 * - sales.customer_balance_after_usd: what the customer owed right after this credit sale, frozen at sale time so a
 *   reprint of the receipt shows what the original showed. Null for sales that were not on account.
 * - customers.payment_terms_days: days of credit this customer gets; null means the store default
 *   (Setting `default_credit_days`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'due_date')) {
                $table->date('due_date')->nullable()->index();
            }
            if (! Schema::hasColumn('sales', 'customer_balance_after_usd')) {
                $table->decimal('customer_balance_after_usd', 12, 4)->nullable();
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'payment_terms_days')) {
                $table->unsignedSmallInteger('payment_terms_days')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'due_date')) {
                $table->dropIndex(['due_date']);
                $table->dropColumn('due_date');
            }
            if (Schema::hasColumn('sales', 'customer_balance_after_usd')) {
                $table->dropColumn('customer_balance_after_usd');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'payment_terms_days')) {
                $table->dropColumn('payment_terms_days');
            }
        });
    }
};
