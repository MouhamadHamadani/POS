<?php

namespace Tests\Feature\I18n;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Validation, flash, service and JSON error messages follow the UI language; error keys never change. */
class ArabicMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function cashierWithShift(string $lang): User
    {
        $cashier = User::factory()->create(['language' => $lang, 'max_discount_pct' => 100]);
        Shift::create([
            'user_id' => $cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 100, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);

        return $cashier;
    }

    public function test_validation_errors_use_the_arabic_message_and_arabic_field_name(): void
    {
        $admin = User::factory()->admin()->create(['language' => 'ar']);

        $this->actingAs($admin)->post('/products', [])
            ->assertSessionHasErrors(['name', 'price_usd', 'category_id']);

        $errors = session('errors');
        $this->assertSame('حقل الاسم مطلوب.', $errors->first('name'));
        $this->assertSame('حقل السعر (دولار) مطلوب.', $errors->first('price_usd'));
        $this->assertSame('حقل الفئة مطلوب.', $errors->first('category_id'));
    }

    public function test_the_same_validation_is_english_for_an_english_user(): void
    {
        $admin = User::factory()->admin()->create(['language' => 'en']);

        $this->actingAs($admin)->post('/products', [])->assertSessionHasErrors('name');

        $this->assertSame('The name field is required.', session('errors')->first('name'));
    }

    public function test_failed_login_is_arabic_for_a_guest_who_chose_arabic(): void
    {
        User::factory()->create(['username' => 'rami']);

        $this->withSession(['locale' => 'ar'])->post('/login', ['login' => 'rami', 'password' => 'wrong'])
            ->assertSessionHasErrors('login');

        $this->assertSame('بيانات الدخول هذه لا تطابق سجلاتنا.', session('errors')->first('login'));
    }

    public function test_insufficient_payment_is_arabic_with_the_same_error_key(): void
    {
        $cashier = $this->cashierWithShift('ar');
        $product = Product::factory()->create(['price_usd' => 10, 'stock_qty' => 5, 'is_taxable' => false]);

        $response = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 1],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.payment.0', 'المبلغ المدفوع غير كافٍ.');
        $this->assertSame(['payment'], array_keys($response->json('error')));
    }

    public function test_insufficient_stock_is_arabic_with_the_same_error_key(): void
    {
        $cashier = $this->cashierWithShift('ar');
        $product = Product::factory()->create(['name' => 'Olive Oil', 'name_ar' => 'زيت زيتون', 'price_usd' => 10, 'stock_qty' => 1, 'track_stock' => true]);

        $response = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 3]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 100],
        ]);

        $response->assertStatus(422);
        $this->assertSame(['stock'], array_keys($response->json('error')));
        $this->assertStringContainsString('المخزون غير كافٍ لـ «زيت زيتون»', $response->json('error.stock.0'));
    }

    public function test_the_same_sale_error_is_english_for_an_english_cashier(): void
    {
        $cashier = $this->cashierWithShift('en');
        $product = Product::factory()->create(['price_usd' => 10, 'stock_qty' => 5, 'is_taxable' => false]);

        $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 1],
        ])->assertStatus(422)->assertJsonPath('error.payment.0', 'Insufficient payment.');
    }

    public function test_a_discount_error_keeps_its_cart_line_key_and_translates_the_product(): void
    {
        $cashier = $this->cashierWithShift('ar');
        $product = Product::factory()->create(['name' => 'Olive Oil', 'name_ar' => 'زيت زيتون', 'price_usd' => 10, 'stock_qty' => 5, 'allow_discount' => false]);

        $response = $this->actingAs($cashier)->postJson('/pos/api/sales', [
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'discount_pct' => 5]],
            'payment' => ['method' => 'cash_usd', 'amount_usd' => 100],
        ]);

        $response->assertStatus(422);
        // the key is the literal string "cart.0.discount" — unchanged, because the sell screen reads it
        $this->assertSame(['cart.0.discount'], array_keys($response->json('error')));
        $this->assertSame('الحسم غير مسموح على «زيت زيتون».', $response->json('error')['cart.0.discount'][0]);
    }

    public function test_pos_json_errors_and_flash_messages_are_arabic(): void
    {
        $cashier = $this->cashierWithShift('ar');
        $this->actingAs($cashier)->getJson('/pos/api/barcode?code=NOPE')->assertStatus(404)->assertJsonPath('error', 'المنتج غير موجود');

        $admin = User::factory()->admin()->create(['language' => 'ar']);
        $category = Category::create(['name' => 'Beverages', 'name_ar' => 'مشروبات', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($admin)->post('/products', [
            'name' => 'Cola', 'name_ar' => 'كولا', 'category_id' => $category->id, 'price_usd' => 1, 'type' => 'simple',
        ])->assertRedirect(route('products.index'))->assertSessionHas('success', 'تم إنشاء «كولا»');

        $this->actingAs($admin)->post('/suppliers', ['name' => 'Acme', 'payment_terms' => 'NET30'])
            ->assertSessionHas('success', 'تم إنشاء «Acme».');
    }

    public function test_shift_and_role_gate_messages_are_arabic(): void
    {
        $cashier = User::factory()->create(['language' => 'ar']);

        $this->actingAs($cashier)->get('/pos')->assertRedirect('/shifts/open')
            ->assertSessionHas('warning', 'يجب فتح وردية قبل البيع.');
        $this->actingAs($cashier)->getJson('/users')->assertForbidden()->assertJsonPath('error', 'غير مسموح');
    }

    public function test_user_management_messages_are_arabic(): void
    {
        // a plain admin may not touch admin-tier accounts at all, so use the super admin
        $admin = User::factory()->superAdmin()->create(['language' => 'ar']);

        $this->actingAs($admin)->post("/users/{$admin->id}/toggle")
            ->assertSessionHasErrors('active');

        $this->assertSame('لا يمكنك تعطيل حسابك الخاص.', session('errors')->first('active'));
    }

    public function test_supplier_payment_amount_message_is_arabic(): void
    {
        $admin = User::factory()->admin()->create(['language' => 'ar']);
        $supplier = Supplier::create(['name' => 'Acme', 'payment_terms' => 'NET30', 'balance' => 10, 'is_active' => true]);

        $this->actingAs($admin)->post("/suppliers/{$supplier->id}/payment", ['amount_usd' => 10, 'method' => 'cash_usd'])
            ->assertSessionHas('success', 'تم تسجيل الدفعة. الرصيد الجديد: $0.00');
    }
}
