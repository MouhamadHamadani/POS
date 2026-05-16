<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name');
            $table->string('symbol', 5);
            $table->decimal('rate', 18, 6)->default(1);
            $table->boolean('is_base')->default(false);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->decimal('rounding_step', 18, 2)->default(0.01);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
