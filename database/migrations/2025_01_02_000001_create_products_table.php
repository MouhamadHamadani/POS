<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('category_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->string('barcode')->unique()->nullable();
            $table->string('sku')->unique()->nullable();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('price_usd', 12, 4);
            $table->decimal('cost_usd', 12, 4)->default(0);
            $table->decimal('wholesale_price_usd', 12, 4)->nullable();
            $table->decimal('vip_price_usd', 12, 4)->nullable();
            $table->decimal('price_lbp', 18, 2)->nullable();
            $table->decimal('cost_lbp', 18, 2)->nullable();
            $table->decimal('stock_qty', 12, 4)->default(0);
            $table->decimal('min_stock', 12, 4)->default(0);
            $table->decimal('max_stock', 12, 4)->nullable();
            $table->string('unit', 20)->default('pcs');
            $table->string('location')->nullable();
            $table->string('type')->default('simple'); // simple|variant|bundle|service
            $table->boolean('is_active')->default(true);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('allow_discount')->default(true);
            $table->boolean('track_stock')->default(true);
            $table->boolean('force_lbp_price')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'category_id']);
            $table->index('type');
            $table->index('stock_qty');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
