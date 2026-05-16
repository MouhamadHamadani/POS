<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type'); // percent|fixed|bogo|bundle|coupon|loyalty
            $table->decimal('value', 12, 4)->default(0);
            $table->decimal('min_cart_amount', 12, 4)->nullable();
            $table->decimal('max_discount_amount', 12, 4)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->integer('max_uses')->nullable();
            $table->integer('uses_count')->default(0);
            $table->integer('max_per_customer')->nullable();
            $table->json('product_ids')->nullable();
            $table->json('category_ids')->nullable();
            $table->string('coupon_code')->unique()->nullable();
            $table->boolean('is_combinable')->default(false);
            $table->boolean('is_automatic')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
