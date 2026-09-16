<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Database\Seeder;

/**
 * The catalogue a prospect clicks through in a pitch.
 *
 * Deliberately small, deliberately invented: every brand here is fictional, so
 * nothing in a demo build can be mistaken for a real supplier's price list or a
 * real client's data. Prices are USD (the stored base currency) at plausible
 * Lebanese mini-market levels — the LBP side is computed live by CurrencyService
 * from the seeded exchange rate, exactly as it is for a paying client.
 *
 * Never chained into DatabaseSeeder: a shipped build must not carry accounts or
 * a catalogue. This runs only into resources/demo/demo-template.sqlite, via
 * `php artisan demo:build-template`.
 */
class DemoSeeder extends Seeder
{
    /** Barcodes use the 29 in-store prefix — valid EAN-13, no real manufacturer. */
    private const PRODUCTS = [
        // [name, name_ar, category, barcode, sku, price_usd, cost_usd, stock]
        ['Cedar Valley Rice 1kg', 'أرز وادي الأرز ١ كغ', 'groceries', '2900000000018', 'DEMO-RICE-1K', 2.40, 1.55, 60],
        ['Byblos Olive Oil 1L', 'زيت زيتون جبيل ١ ل', 'groceries', '2900000000025', 'DEMO-OIL-1L', 9.50, 6.20, 24],
        ['Sunrise Lentils 500g', 'عدس الشروق ٥٠٠ غ', 'groceries', '2900000000032', 'DEMO-LENT-500', 1.80, 1.05, 45],
        ['Mount Sannine Salt 1kg', 'ملح جبل صنين ١ كغ', 'groceries', '2900000000049', 'DEMO-SALT-1K', 0.60, 0.25, 80],
        ['Golden Souk Sugar 1kg', 'سكر السوق الذهبي ١ كغ', 'groceries', '2900000000056', 'DEMO-SUGR-1K', 1.20, 0.75, 70],
        ['Demo Farms Eggs (12)', 'بيض مزارع النموذج (١٢)', 'groceries', '2900000000063', 'DEMO-EGGS-12', 3.20, 2.30, 30],

        ['Cedar Cola 330ml', 'كولا الأرز ٣٣٠ مل', 'beverages', '2900000000070', 'DEMO-COLA-330', 1.10, 0.55, 120],
        ['Cedar Cola Zero 330ml', 'كولا الأرز زيرو ٣٣٠ مل', 'beverages', '2900000000087', 'DEMO-COLAZ-330', 1.10, 0.55, 90],
        ['Jeita Spring Water 500ml', 'مياه نبع جعيتا ٥٠٠ مل', 'beverages', '2900000000094', 'DEMO-WATR-500', 0.50, 0.18, 200],
        ['Jeita Spring Water 1.5L', 'مياه نبع جعيتا ١.٥ ل', 'beverages', '2900000000100', 'DEMO-WATR-15L', 1.00, 0.40, 100],
        ['Bekaa Orange Juice 1L', 'عصير برتقال البقاع ١ ل', 'beverages', '2900000000117', 'DEMO-JUIC-1L', 2.75, 1.70, 36],
        ['Beirut Roast Coffee 200g', 'بن بيروت المحمص ٢٠٠ غ', 'beverages', '2900000000124', 'DEMO-COFF-200', 5.50, 3.60, 25],

        ['Zaatar Crisps 45g', 'رقائق بالزعتر ٤٥ غ', 'snacks', '2900000000131', 'DEMO-CRSP-Z45', 0.90, 0.42, 150],
        ['Chili Crisps 45g', 'رقائق حارة ٤٥ غ', 'snacks', '2900000000148', 'DEMO-CRSP-C45', 0.90, 0.42, 140],
        ['Cedar Chocolate Bar 40g', 'لوح شوكولا الأرز ٤٠ غ', 'snacks', '2900000000155', 'DEMO-CHOC-40', 1.35, 0.78, 110],
        ['Roasted Mixed Nuts 200g', 'مكسرات مشكلة محمصة ٢٠٠ غ', 'snacks', '2900000000162', 'DEMO-NUTS-200', 6.40, 4.10, 40],
        ['Sesame Barazek 250g', 'برازق بالسمسم ٢٥٠ غ', 'snacks', '2900000000179', 'DEMO-BRZK-250', 4.20, 2.60, 28],
    ];

    /** One account per client-facing role. No super_admin: that tier is ours, not a prospect's. */
    private const USERS = [
        // [username, name, role, max_discount_pct, language]
        ['demo_admin', 'Demo Admin', User::ROLE_ADMIN, 100, 'en'],
        ['demo_manager', 'Demo Manager', User::ROLE_MANAGER, 50, 'en'],
        ['demo_cashier', 'Demo Cashier', User::ROLE_CASHIER, 10, 'en'],
        ['demo_stock', 'Demo Stock Keeper', User::ROLE_STOCK, 0, 'ar'],
    ];

    public function run(): void
    {
        $admin = $this->users();
        $categories = $this->categories();
        $this->products($categories, $admin);
        $this->customers();
        $this->storefront();
    }

    private function users(): User
    {
        $password = Demo::password();

        foreach (self::USERS as [$username, $name, $role, $discount, $language]) {
            User::updateOrCreate(
                ['username' => $username],
                [
                    'name' => $name,
                    'email' => $username.'@demo.lebasouk.local',
                    'password' => $password,
                    'role' => $role,
                    'language' => $language,
                    'is_active' => true,
                    'max_discount_pct' => $discount,
                    'email_verified_at' => now(),
                ]
            );
        }

        return User::where('username', 'demo_admin')->firstOrFail();
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $rows = [
            ['groceries', 'Groceries', 'بقالة', 1],
            ['beverages', 'Beverages', 'مشروبات', 2],
            ['snacks', 'Snacks', 'وجبات خفيفة', 3],
        ];

        $categories = [];

        foreach ($rows as [$slug, $name, $nameAr, $order]) {
            $categories[$slug] = Category::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'name_ar' => $nameAr, 'sort_order' => $order, 'is_active' => true]
            );
        }

        return $categories;
    }

    /** @param array<string, Category> $categories */
    private function products(array $categories, User $admin): void
    {
        $vat = Tax::where('is_default', true)->first();

        foreach (self::PRODUCTS as [$name, $nameAr, $slug, $barcode, $sku, $price, $cost, $stock]) {
            Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'category_id' => $categories[$slug]->id,
                    'tax_id' => $vat?->id,
                    'barcode' => $barcode,
                    'name' => $name,
                    'name_ar' => $nameAr,
                    'price_usd' => $price,
                    'cost_usd' => $cost,
                    // price_lbp stays null on purpose: LBP is derived from the
                    // live rate by CurrencyService, not frozen into the row.
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

    private function customers(): void
    {
        $rows = [
            // Names that cannot be mistaken for a real person: "tajribi" and
            // "namouzaj" are Arabic for "test" and "sample".
            ['Rami Tajribi (Demo)', '+961 70 000 001', Customer::GROUP_RETAIL, 320, 0],
            ['Salma Namouzaj (Demo)', '+961 70 000 002', Customer::GROUP_VIP, 1450, 250],
        ];

        foreach ($rows as [$name, $phone, $group, $points, $creditLimit]) {
            Customer::updateOrCreate(
                ['phone' => $phone],
                [
                    'name' => $name,
                    'customer_group' => $group,
                    'loyalty_points' => $points,
                    'loyalty_tier' => $points >= 2000 ? 'gold' : ($points >= 500 ? 'silver' : 'bronze'),
                    'credit_limit' => $creditLimit,
                    'balance' => 0,
                    'is_active' => true,
                    'notes' => 'Seeded demo customer — not a real person.',
                ]
            );
        }
    }

    /**
     * Identity for the storefront a prospect sees on screen and on the receipt.
     * Fictional, and locked in the demo build (settings > general is blocked)
     * so the baseline is identical for every meeting.
     */
    private function storefront(): void
    {
        $settings = [
            ['business_name', 'LebaSouk Demo Store', 'general'],
            ['business_name_ar', 'متجر ليبا سوق التجريبي', 'general'],
            ['address', 'Demo Street, Beirut, Lebanon', 'general'],
            ['phone', '+961 1 000 000', 'general'],
            ['tax_number', 'DEMO-VAT-0000', 'general'],
            ['receipt_header', "LebaSouk Demo Store\nDemonstration only", 'receipt'],
            ['receipt_footer', 'Thank you — this is a demo receipt.', 'receipt'],
        ];

        foreach ($settings as [$key, $value, $group]) {
            Setting::set($key, $value, $group, 'string');
        }
    }
}
