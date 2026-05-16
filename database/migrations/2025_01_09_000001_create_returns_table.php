<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('return_number')->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reason'); // defective|wrong_item|customer_preference|other
            $table->text('reason_note')->nullable();
            $table->string('refund_method'); // cash_usd|cash_lbp|account|exchange
            $table->decimal('refund_amount_usd', 12, 4);
            $table->decimal('refund_amount_lbp', 18, 2)->default(0);
            $table->string('status')->default('pending'); // pending|approved|completed|rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
