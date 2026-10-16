<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expense categories, fixed (rent, salaries...) or running (electricity, fuel...).
 *
 * The defaults are inserted here rather than by a seeder so that an install that
 * only runs `migrate` — the packaged app does, on every version change — gets
 * them too. The admin owns the list from then on (Settings > Expenses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('kind', 10); // fixed|running
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['kind', 'sort_order']);
        });

        $defaults = [
            ['Rent', 'إيجار', 'fixed'],
            ['Salaries', 'رواتب', 'fixed'],
            ['Subscriptions', 'اشتراكات', 'fixed'],
            ['Insurance', 'تأمين', 'fixed'],
            ['Electricity', 'كهرباء', 'running'],
            ['Generator', 'اشتراك مولد', 'running'],
            ['Water', 'مياه', 'running'],
            ['Fuel', 'محروقات', 'running'],
            ['Internet & phone', 'إنترنت وهاتف', 'running'],
            ['Maintenance', 'صيانة', 'running'],
            ['Other', 'أخرى', 'running'],
        ];

        $now = now();
        DB::table('expense_categories')->insert(array_map(fn ($row, $i) => [
            'name' => $row[0], 'name_ar' => $row[1], 'kind' => $row[2],
            'is_active' => true, 'sort_order' => $i + 1,
            'created_at' => $now, 'updated_at' => $now,
        ], $defaults, array_keys($defaults)));
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
