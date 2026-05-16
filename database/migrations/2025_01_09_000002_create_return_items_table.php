<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('qty_returned', 12, 4);
            $table->decimal('refund_amount_usd', 12, 4);
            $table->boolean('restock')->default(true);
            $table->string('condition')->default('good'); // good|damaged|expired
            $table->timestamps();

            $table->index('return_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};
