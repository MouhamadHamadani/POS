<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money spent on running the business. Stored the way cash_movements is: USD is
 * the base, LBP is the converted twin, and `currency` records which one the
 * amount was typed in.
 *
 * `paid_from = drawer` expenses also own a cash_movements row (type out) on the
 * paying user's open shift; `outside` ones (bank, the owner's pocket) are for
 * reports only and carry no shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount_usd', 12, 4);
            $table->decimal('amount_lbp', 18, 2);
            $table->string('currency', 5); // usd|lbp
            $table->string('payee')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('paid_from', 10)->default('outside'); // drawer|outside
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_movement_id')->nullable()->constrained('cash_movements')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('date');
            $table->index(['expense_category_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
