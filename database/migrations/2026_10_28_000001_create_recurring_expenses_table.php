<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring expense templates (rent every month, a weekly cleaner, a yearly licence) and the
 * link from the expenses they produce.
 *
 * A template stores its amount the way expenses do: USD is the base, LBP its twin, `currency`
 * the one it was typed in. A generated expense copies the typed amount and converts it at the
 * rate of the day it is generated.
 *
 * `expenses.recurring_expense_id` + `period_date` identify which occurrence an expense is. The
 * unique pair is what makes generation idempotent; soft-deleted expenses keep their pair, so an
 * automatic expense the owner deleted is not brought back by the next run. Manual expenses have
 * both NULL, and NULLs never collide in a unique index. Templates are soft-deleted so the link
 * (and the "Auto" badge) outlives them. A generated expense has no author, hence the
 * nullable `created_by`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount_usd', 12, 4);
            $table->decimal('amount_lbp', 18, 2);
            $table->string('currency', 5); // usd|lbp
            $table->string('frequency', 10); // weekly|monthly|yearly
            $table->unsignedTinyInteger('day_of_week')->nullable(); // weekly: 1 = Monday ... 7 = Sunday
            $table->unsignedTinyInteger('day_of_month')->nullable(); // monthly: 1-31, the last day in a shorter month
            $table->date('start_date'); // yearly: the day and month it recurs on
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('last_generated_for')->nullable(); // the latest occurrence already handled
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'start_date']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')->nullable()->constrained('recurring_expenses')->nullOnDelete();
            $table->date('period_date')->nullable();
            $table->unique(['recurring_expense_id', 'period_date']);
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['recurring_expense_id', 'period_date']);
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropColumn('period_date');
        });

        Schema::dropIfExists('recurring_expenses');
    }
};
