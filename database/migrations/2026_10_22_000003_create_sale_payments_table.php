<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The itemised record of how a sale was paid: one row per payment line.
 *
 * `sales` keeps its flat payment columns (amount_tendered_usd/lbp, change_*, amount_card_usd,
 * amount_credit_usd, card_*). Those stay the authoritative CASH-DRAWER ledger: ShiftService,
 * the receipts and a lot of tests read them. This table is the display and reporting record,
 * and SaleService writes both in the same transaction.
 *
 * - method: cash | card | wish | credit (on account).
 * - currency / amount: the line as paid, in its own currency. For cash that is what was
 *   TENDERED in that currency; the change handed back stays on the sale. Wish is USD in v1;
 *   the column exists so LBP can follow without a migration.
 * - amount_usd: the same amount in USD (LBP at the sale's own rate).
 * - reference: card approval number or Wish transaction reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->string('currency', 3)->default('USD');
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_usd', 12, 4);
            $table->string('reference')->nullable();
            $table->string('card_type')->nullable();
            $table->timestamps();

            $table->index('sale_id');
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
