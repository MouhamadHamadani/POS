<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The debt book.
 *
 * `customer_payments` is the header of a repayment (or of a write-off, `kind = write_off`): who paid, how much in
 * which currency, at what rate, in which shift. `amount` is in `currency`; `amount_usd` is what it came to in USD,
 * and USD is what a debt is kept in, so a devalued pound never changes what a customer owes.
 *
 * `customer_ledger_entries` is the source of truth and is append-only: nothing updates or deletes a row, a mistake
 * is put right with a reversing row. `amount_usd` is signed: + raises the debt, - lowers it.
 *   - what a receipt still owes   = SUM(amount_usd) WHERE sale_id = X
 *   - the opening / unlinked pot  = entries with sale_id NULL (a balance from before the book, store credit)
 *   - what a customer owes        = SUM(amount_usd) of their entries (cached on customers.balance)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_payments')) {
            Schema::create('customer_payments', function (Blueprint $table) {
                $table->id();
                $table->string('number')->unique();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 12)->default('payment');   // payment | write_off
                $table->string('method', 20);                      // cash | card | wish | bank_transfer | other
                $table->string('currency', 3)->default('USD');     // USD | LBP
                $table->decimal('amount', 18, 4);
                $table->decimal('exchange_rate', 18, 6)->nullable(); // LBP only
                $table->decimal('amount_usd', 12, 4);
                $table->dateTime('paid_at');
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('void_reason')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'paid_at']);
                $table->index('shift_id');
            });
        }

        if (! Schema::hasTable('customer_ledger_entries')) {
            Schema::create('customer_ledger_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                // opening | credit_sale | payment | write_off | return_credit | void_reversal | adjustment
                $table->string('type', 20);
                $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('customer_payment_id')->nullable()->constrained('customer_payments')->restrictOnDelete();
                $table->foreignId('return_id')->nullable()->constrained('returns')->restrictOnDelete();
                $table->decimal('amount_usd', 12, 4);
                $table->dateTime('entry_date');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'entry_date']);
                $table->index('sale_id');
                $table->index('customer_payment_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger_entries');
        Schema::dropIfExists('customer_payments');
    }
};
