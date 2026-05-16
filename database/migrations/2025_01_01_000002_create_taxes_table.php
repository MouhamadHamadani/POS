<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->decimal('rate', 7, 4);
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('is_default')->default(false);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_default');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
