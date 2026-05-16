<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // receipt|invoice|label|po|delivery|grn
            $table->longText('template_html'); // Blade-compatible
            $table->string('paper_size')->default('80mm'); // 58mm|80mm|A4|A5|label
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
    }
};
