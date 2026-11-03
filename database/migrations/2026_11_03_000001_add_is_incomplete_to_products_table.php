<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product quick-created from the purchase order form has a name, category and cost but no
 * sell price yet. `is_incomplete` keeps it off the sell screen (Product::scopeSellable) until
 * someone saves it with a price; ProductController::update clears it then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_incomplete')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_incomplete']);
            $table->dropColumn('is_incomplete');
        });
    }
};
