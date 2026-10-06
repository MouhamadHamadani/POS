<?php

namespace Tests\Feature\I18n;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Receipt language is a store setting (en | ar | bilingual), independent of the
 * language the cashier uses on screen.
 */
class ReceiptLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function saleFor(User $cashier): Sale
    {
        $shift = Shift::create([
            'user_id' => $cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $product = Product::factory()->create(['name' => 'Olive Oil', 'name_ar' => 'زيت زيتون']);
        $sale = Sale::create([
            'receipt_number' => 'REC-LANG-'.str_pad((string) (Sale::count() + 1), 3, '0', STR_PAD_LEFT), 'user_id' => $cashier->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 10, 'discount_amount_usd' => 0, 'tax_amount_usd' => 1.10, 'total_usd' => 11.10,
            'total_lbp' => 1110000, 'exchange_rate' => 100000, 'payment_method' => Sale::METHOD_CASH_USD,
            'amount_tendered_usd' => 20, 'change_usd' => 8.90, 'change_lbp' => 0, 'status' => Sale::STATUS_COMPLETED,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'Olive Oil', 'qty' => 2,
            'unit_price_usd' => 5, 'cost_usd' => 2, 'line_total_usd' => 10, 'tax_rate' => 0.11, 'tax_amount_usd' => 1.10,
        ]);

        return $sale;
    }

    private function receipt(string $uiLang, string $receiptLang, string $footer = 'Come again!')
    {
        Setting::set('receipt_language', $receiptLang, 'receipt');
        Setting::set('receipt_footer', $footer, 'receipt');
        $cashier = User::factory()->create(['language' => $uiLang]);
        $sale = $this->saleFor($cashier);

        return $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print");
    }

    public function test_arabic_receipt_is_rtl_arabic_only_with_the_arabic_item_name(): void
    {
        $this->receipt('en', 'ar')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('المجموع')
            ->assertSee('زيت زيتون')
            ->assertDontSee('TOTAL')
            ->assertDontSee('Change USD');
    }

    public function test_english_receipt_stays_english_even_when_the_cashier_uses_arabic(): void
    {
        $this->receipt('ar', 'en')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('TOTAL')
            ->assertSee('Olive Oil')
            ->assertDontSee('المجموع')
            ->assertDontSee('زيت زيتون');
    }

    public function test_bilingual_receipt_carries_both_languages_and_both_item_names(): void
    {
        $this->receipt('en', 'bilingual')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false)
            ->assertSee('TOTAL')
            ->assertSee('المجموع')
            ->assertSee('Olive Oil')
            ->assertSee('زيت زيتون');
    }

    public function test_bilingual_is_the_default_when_the_setting_was_never_saved(): void
    {
        $cashier = User::factory()->create();
        $sale = $this->saleFor($cashier);

        $this->actingAs($cashier)->get("/pos/receipts/{$sale->id}/print")
            ->assertSee('TOTAL')->assertSee('المجموع');
    }

    public function test_an_empty_footer_falls_back_to_the_default_in_the_receipt_language(): void
    {
        $this->receipt('en', 'ar', '')->assertSee('شكراً لزيارتكم')->assertDontSee('Thank you for your business!');
        $this->receipt('en', 'en', '')->assertSee('Thank you for your business!');
        $this->receipt('en', 'bilingual', '')->assertSee('Thank you for your business!')->assertSee('شكراً لزيارتكم');
    }

    public function test_a_custom_footer_is_printed_as_typed_and_never_replaced(): void
    {
        $this->receipt('en', 'ar', 'Come again!')->assertSee('Come again!')->assertDontSee('شكراً لزيارتكم');
    }

    public function test_amounts_stay_western_digits_and_left_to_right(): void
    {
        $this->receipt('ar', 'ar')
            ->assertSee('$11.10')
            ->assertSee('class="amt"', false)
            ->assertDontSee('١١');
    }

    public function test_test_print_honours_the_unsaved_receipt_language(): void
    {
        $admin = User::factory()->admin()->create();
        $post = fn (string $lang) => $this->actingAs($admin)->post('/settings/receipt/test-print', [
            'group' => 'receipt',
            'settings' => ['receipt_width' => 58, 'receipt_language' => $lang, 'receipt_header' => '', 'receipt_footer' => ''],
        ]);

        // the watermark carries both languages whatever the setting
        $post('en')->assertOk()->assertSee('TEST PRINT')->assertSee('طباعة تجريبية')->assertDontSee('صنف تجريبي');
        $post('ar')->assertOk()->assertSee('<html lang="ar" dir="rtl">', false)->assertSee('صنف تجريبي')->assertDontSee('Sample Item A');
        $post('bilingual')->assertOk()->assertSee('Sample Item A')->assertSee('صنف تجريبي');
    }

    public function test_the_bundled_receipt_fonts_exist(): void
    {
        foreach (['arabic', 'latin'] as $subset) {
            foreach ([400, 700] as $weight) {
                $this->assertFileExists(public_path("fonts/cairo-{$subset}-{$weight}-normal.woff2"));
            }
        }
    }
}
