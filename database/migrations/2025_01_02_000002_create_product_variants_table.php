<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->json('attributes'); // {size:"L", color:"red"}
            $table->string('sku')->unique()->nullable();
            $table->string('barcode')->unique()->nullable();
            $table->decimal('price_modifier', 12, 4)->default(0);
            $table->decimal('stock_qty', 12, 4)->default(0);
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
