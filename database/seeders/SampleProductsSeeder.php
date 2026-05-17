<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Seeder;

class SampleProductsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $vat = Tax::where('is_default', true)->first();

        $beverages = Category::updateOrCreate(['slug' => 'beverages'], [
            'name' => 'Beverages', 'name_ar' => 'مشروبات', 'sort_order' => 1, 'is_active' => true,
        ]);
        $snacks = Category::updateOrCreate(['slug' => 'snacks'], [
            'name' => 'Snacks', 'name_ar' => 'وجبات خفيفة', 'sort_order' => 2, 'is_active' => true,
        ]);
        $dairy = Category::updateOrCreate(['slug' => 'dairy'], [
            'name' => 'Dairy', 'name_ar' => 'ألبان', 'sort_order' => 3, 'is_active' => true,
        ]);

        $products = [
            ['Coca Cola 330ml', 'كوكا كولا 330مل', $beverages, '6111234500011', 'COKE-330', 1.50, 0.85, 100],
            ['Pepsi 330ml', 'بيبسي 330مل', $beverages, '6111234500028', 'PEPSI-330', 1.50, 0.80, 100],
            ['Mineral Water 500ml', 'مياه معدنية 500مل', $beverages, '6111234500035', 'WATER-500', 0.75, 0.30, 200],
            ['Lays Salt 50g', 'ليز ملح 50غ', $snacks, '6111234500042', 'LAYS-S-50', 1.25, 0.60, 80],
            ['Snickers Bar', 'سنيكرز', $snacks, '6111234500059', 'SNCK-BAR', 1.75, 0.95, 60],
            ['Milk 1L', 'حليب 1لتر', $dairy, '6111234500066', 'MILK-1L', 2.50, 1.40, 40],
            ['Cheese 200g', 'جبنة 200غ', $dairy, '6111234500073', 'CHEESE-200', 4.50, 2.80, 25],
        ];

        foreach ($products as [$name, $nameAr, $cat, $barcode, $sku, $price, $cost, $stock]) {
            Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'category_id' => $cat->id,
                    'tax_id' => $vat?->id,
                    'barcode' => $barcode,
                    'name' => $name,
                    'name_ar' => $nameAr,
                    'price_usd' => $price,
                    'cost_usd' => $cost,
                    'stock_qty' => $stock,
                    'min_stock' => 10,
                    'unit' => 'pcs',
                    'type' => Product::TYPE_SIMPLE,
                    'is_active' => true,
                    'is_taxable' => true,
                    'allow_discount' => true,
                    'track_stock' => true,
                    'created_by' => $admin->id,
                ]
            );
        }
    }
}
