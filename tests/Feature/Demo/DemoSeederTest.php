<?php

namespace Tests\Feature\Demo;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\CurrencyService;
use Database\Seeders\DefaultCurrenciesSeeder;
use Database\Seeders\DefaultSettingsSeeder;
use Database\Seeders\DefaultTaxSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The demo catalogue itself: small, fictional, bilingual, and carrying no
 * account a prospect should not have.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([DefaultCurrenciesSeeder::class, DefaultTaxSeeder::class, DefaultSettingsSeeder::class]);
        $this->seed(DemoSeeder::class);
    }

    public function test_it_seeds_the_agreed_catalogue(): void
    {
        $this->assertSame(3, Category::count());
        $this->assertGreaterThanOrEqual(15, Product::count());
        $this->assertLessThanOrEqual(18, Product::count());
        $this->assertSame(2, Customer::count());
        $this->assertSame(4, User::count());
    }

    public function test_it_seeds_no_super_admin(): void
    {
        $this->assertSame(0, User::where('role', User::ROLE_SUPER_ADMIN)->count());
        $this->assertEqualsCanonicalizing(
            [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_CASHIER, User::ROLE_STOCK],
            User::pluck('role')->all()
        );
    }

    public function test_every_demo_account_uses_the_documented_password(): void
    {
        foreach (User::all() as $user) {
            $this->assertTrue(
                Hash::check(config('pos.demo_password'), $user->password),
                "{$user->username} does not accept the documented demo password"
            );
        }
    }

    public function test_every_product_is_bilingual_and_scannable(): void
    {
        foreach (Product::all() as $product) {
            $this->assertNotEmpty($product->name_ar, "{$product->name} has no Arabic name");
            $this->assertMatchesRegularExpression('/^\d{13}$/', (string) $product->barcode);
            $this->assertSame(
                $this->ean13CheckDigit(substr($product->barcode, 0, 12)),
                substr($product->barcode, -1),
                "{$product->barcode} is not a valid EAN-13 — a scanner would reject it"
            );
        }
    }

    public function test_prices_convert_to_lbp_through_currency_service(): void
    {
        $water = Product::where('sku', 'DEMO-WATR-500')->firstOrFail();
        $currency = app(CurrencyService::class);

        // 0.50 USD at the seeded 90,000 rate = 45,000 LBP, already on the
        // 1,000 rounding step.
        $this->assertSame(0.50, (float) $water->price_usd);
        $this->assertSame(45000.0, $currency->usdToLbp((float) $water->price_usd));
    }

    public function test_the_seeder_is_safe_to_run_twice(): void
    {
        $products = Product::count();
        $users = User::count();

        $this->seed(DemoSeeder::class);

        $this->assertSame($products, Product::count());
        $this->assertSame($users, User::count());
    }

    private function ean13CheckDigit(string $twelve): string
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 ? 3 : 1);
        }

        return (string) ((10 - $sum % 10) % 10);
    }
}
