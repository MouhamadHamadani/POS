<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash_usd', 12, 4)->default(0);
            $table->decimal('opening_cash_lbp', 18, 2)->default(0);
            $table->decimal('closing_cash_usd', 12, 4)->nullable();
            $table->decimal('closing_cash_lbp', 18, 2)->nullable();
            $table->decimal('expected_cash_usd', 12, 4)->nullable();
            $table->decimal('expected_cash_lbp', 18, 2)->nullable();
            $table->decimal('variance_usd', 12, 4)->nullable();
            $table->decimal('variance_lbp', 18, 2)->nullable();
            $table->string('status')->default('open'); // open|closed
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'user_id']);
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
