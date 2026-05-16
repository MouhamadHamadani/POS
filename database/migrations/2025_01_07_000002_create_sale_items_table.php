<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name'); // snapshot
            $table->string('product_sku')->nullable(); // snapshot
            $table->decimal('qty', 12, 4);
            $table->decimal('unit_price_usd', 12, 4);
            $table->decimal('cost_usd', 12, 4)->default(0); // snapshot for COGS
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('discount_amount_usd', 12, 4)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount_usd', 12, 4)->default(0);
            $table->decimal('line_total_usd', 12, 4);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_returned')->default(false);
            $table->decimal('returned_qty', 12, 4)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('sale_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
