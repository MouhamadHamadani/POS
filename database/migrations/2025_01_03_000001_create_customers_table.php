<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('company_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('customer_group')->default('retail'); // retail|wholesale|vip
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->integer('loyalty_points')->default(0);
            $table->string('loyalty_tier')->default('bronze'); // bronze|silver|gold
            $table->boolean('tax_exempt')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('birth_date')->nullable();
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('phone');
            $table->index('customer_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
