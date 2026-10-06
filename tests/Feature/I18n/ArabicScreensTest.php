<?php

namespace Tests\Feature\I18n;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An Arabic user gets Arabic, right-to-left screens: <html lang="ar" dir="rtl">, a known
 * Arabic label, and none of the English label that screen used to show.
 */
class ArabicScreensTest extends TestCase
{
    use RefreshDatabase;

    private const RTL = '<html lang="ar" dir="rtl">';

    private function arabic(string $state = 'manager'): User
    {
        $factory = User::factory();   // a plain user is a cashier

        return ($state === 'cashier' ? $factory : $factory->{$state}())->create(['language' => 'ar']);
    }

    private function openShift(User $user): void
    {
        Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 100, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
    }

    public static function managerScreens(): array
    {
        return [
            'dashboard' => ['/dashboard', 'إيرادات اليوم', "Today's Revenue"],
            'products' => ['/products', 'منتج جديد', 'New Product'],
            'product form' => ['/products/create', 'معلومات أساسية', 'Basic Info'],
            'categories' => ['/categories', 'إضافة فئة جديدة', 'Add new category'],
            'customers' => ['/customers', 'زبون جديد', 'New Customer'],
            'suppliers' => ['/suppliers', 'مورّد جديد', 'New Supplier'],
            'purchase orders' => ['/purchase-orders', 'أمر شراء جديد', 'New PO'],
            'returns' => ['/returns', 'مرتجع جديد', 'New Return'],
            'reports' => ['/reports', 'اختر تقريراً', 'Pick a report'],
            'daily sales report' => ['/reports/sales/daily', 'ملخص المبيعات اليومية', 'Daily Sales Summary'],
            'stock levels report' => ['/reports/inventory/stock-levels', 'مستويات المخزون', 'Inventory Stock Levels'],
            'profit and loss' => ['/reports/financial/pnl', 'الأرباح والخسائر', 'Profit & Loss'],
            'profile' => ['/profile', 'معلومات الحساب', 'Account info'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('managerScreens')]
    public function test_manager_screens_are_arabic_and_rtl(string $url, string $arabic, string $english): void
    {
        $this->actingAs($this->arabic('manager'))->get($url)
            ->assertOk()
            ->assertSee(self::RTL, false)
            ->assertSee($arabic)
            ->assertDontSee($english);
    }

    public function test_admin_settings_and_users_are_arabic(): void
    {
        $admin = $this->arabic('admin');

        $this->actingAs($admin)->get('/settings')->assertOk()->assertSee(self::RTL, false)
            ->assertSee('لغة المتجر الافتراضية')->assertDontSee('Store default language');
        $this->actingAs($admin)->get('/settings?tab=receipt')->assertOk()->assertSee('لغة الإيصال')->assertDontSee('Receipt language');
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee(self::RTL, false)
            ->assertSee('مستخدم جديد')->assertDontSee('+ New User');
    }

    public function test_cashier_sell_screen_is_arabic_and_rtl(): void
    {
        $cashier = $this->arabic('cashier');
        $this->openShift($cashier);
        Product::factory()->create(['name' => 'Olive Oil', 'name_ar' => 'زيت زيتون']);

        $this->actingAs($cashier)->get('/pos')
            ->assertOk()
            ->assertSee(self::RTL, false)
            ->assertSee('ابحث بالاسم أو SKU')
            ->assertSee('السلة')
            ->assertDontSee('Search by name, SKU')
            ->assertDontSee('Cart is empty')
            ->assertSee(route('locale.switch', 'en'), false);
    }

    public function test_the_shift_screens_are_arabic(): void
    {
        $cashier = $this->arabic('cashier');

        $this->actingAs($cashier)->get('/shifts/open')->assertOk()->assertSee(self::RTL, false)
            ->assertSee('فتح الوردية والانتقال إلى نقطة البيع')->assertDontSee('Open Shift &amp; Go to POS', false);

        $this->openShift($cashier);
        $this->actingAs($cashier)->get('/shifts/close')->assertOk()->assertSee('ملخص الوردية')->assertDontSee('Shift summary');
    }

    public function test_nothing_in_the_arabic_sidebar_is_english(): void
    {
        $this->actingAs($this->arabic('manager'))->get('/dashboard')
            ->assertSee('نقطة البيع')->assertSee('لوحة التحكم')->assertSee('أوامر الشراء')
            ->assertDontSee('Purchase Orders')->assertDontSee('Log out');
    }

    public function test_product_names_follow_the_ui_language(): void
    {
        Product::factory()->create(['name' => 'Olive Oil', 'name_ar' => 'زيت زيتون', 'stock_qty' => 0, 'min_stock' => 5, 'track_stock' => true]);

        $this->actingAs($this->arabic('manager'))->get('/dashboard')->assertSee('زيت زيتون');
        $this->actingAs(User::factory()->manager()->create(['language' => 'en']))->get('/dashboard')->assertSee('Olive Oil');
    }

    public function test_statuses_and_enum_values_are_translated(): void
    {
        $manager = $this->arabic('manager');
        $supplier = Supplier::create(['name' => 'Acme', 'payment_terms' => 'NET30', 'is_active' => true]);
        $po = PurchaseOrder::create(['po_number' => 'PO-1', 'supplier_id' => $supplier->id, 'user_id' => $manager->id, 'status' => 'partial']);
        $customer = Customer::create(['name' => 'Rami', 'customer_group' => 'wholesale', 'is_active' => true]);

        $this->actingAs($manager)->get('/purchase-orders')->assertSee('جزئي')->assertDontSee('Partial');
        $this->actingAs($manager)->get("/suppliers/{$supplier->id}")->assertSee('صافي 30 يوماً');
        $this->actingAs($manager)->get('/customers')->assertSee('جملة')->assertDontSee('Wholesale');
    }

    public function test_a_sale_status_label_is_translated_and_an_unknown_one_falls_back(): void
    {
        $sale = new Sale(['status' => Sale::STATUS_PARTIAL_REFUND, 'payment_method' => 'cash_usd']);
        app()->setLocale('ar');
        $this->assertSame('مُسترد جزئياً', $sale->statusLabel());
        $this->assertSame('نقداً (دولار)', $sale->paymentMethodLabel());

        $sale->status = 'brand_new_status';
        $this->assertSame('Brand New Status', $sale->statusLabel());

        app()->setLocale('en');
        $this->assertSame('Brand New Status', $sale->statusLabel());
    }

    public function test_diff_for_humans_is_localised_and_keeps_western_digits(): void
    {
        \Carbon\Carbon::setLocale('ar');
        $text = now()->subMinutes(5)->diffForHumans();

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text);
        $this->assertDoesNotMatchRegularExpression('/[٠-٩]/u', $text, 'Arabic-Indic digits leaked into a relative date');
    }
}
