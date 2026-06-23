<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->unique()->words(3, true),
            'sku' => 'SKU-' . fake()->unique()->numberBetween(100_000, 999_999),
            'price_usd' => fake()->randomFloat(2, 1, 100),
            'cost_usd' => fake()->randomFloat(2, 0.50, 50),
            'stock_qty' => 50,
            'min_stock' => 5,
            'unit' => 'pcs',
            'type' => Product::TYPE_SIMPLE,
            'is_active' => true,
            'is_taxable' => true,
            'allow_discount' => true,
            'track_stock' => true,
        ];
    }
}
