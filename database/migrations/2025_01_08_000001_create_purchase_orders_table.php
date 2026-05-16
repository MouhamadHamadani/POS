<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('po_number')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft'); // draft|sent|partial|received|closed|cancelled
            $table->decimal('subtotal_usd', 14, 4)->default(0);
            $table->decimal('tax_amount_usd', 14, 4)->default(0);
            $table->decimal('shipping_usd', 14, 4)->default(0);
            $table->decimal('total_usd', 14, 4)->default(0);
            $table->date('expected_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('supplier_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'expected_at']);
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
