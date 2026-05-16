<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('receipt_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->decimal('subtotal_usd', 12, 4);
            $table->decimal('discount_amount_usd', 12, 4)->default(0);
            $table->decimal('tax_amount_usd', 12, 4)->default(0);
            $table->decimal('total_usd', 12, 4);
            $table->decimal('total_lbp', 18, 2)->nullable();
            $table->decimal('exchange_rate', 18, 6);
            $table->string('payment_method'); // cash_usd|cash_lbp|card|mixed|credit|split
            $table->decimal('amount_tendered_usd', 12, 4)->nullable();
            $table->decimal('amount_tendered_lbp', 18, 2)->nullable();
            $table->decimal('amount_card_usd', 12, 4)->default(0);
            $table->decimal('amount_credit_usd', 12, 4)->default(0);
            $table->decimal('change_usd', 12, 4)->default(0);
            $table->decimal('change_lbp', 18, 2)->default(0);
            $table->integer('loyalty_points_earned')->default(0);
            $table->integer('loyalty_points_redeemed')->default(0);
            $table->string('card_type')->nullable();
            $table->string('card_reference')->nullable();
            $table->string('status')->default('completed'); // completed|voided|refunded|on_hold|partial_refund
            $table->text('notes')->nullable();
            $table->boolean('is_synced')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('receipt_number');
            $table->index(['status', 'created_at']);
            $table->index('shift_id');
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
