<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name'); // snapshot
            $table->decimal('qty_ordered', 12, 4);
            $table->decimal('qty_received', 12, 4)->default(0);
            $table->decimal('cost_usd', 12, 4);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('line_total_usd', 14, 4);
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
