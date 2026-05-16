<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type'); // add|remove|set|count|sale|return|po_receive
            $table->decimal('qty_before', 12, 4);
            $table->decimal('qty_change', 12, 4);
            $table->decimal('qty_after', 12, 4);
            $table->string('reason')->nullable();
            $table->string('reference_type')->nullable(); // sale|purchase_order|return|manual
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};
