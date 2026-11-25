<?php

namespace Tests\Feature\Pos;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use App\Services\ReceiptPrintService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Part of the receipt used to be laid out as wide as the whole paper, while a thermal printer only prints
 * ~72 mm of an 80 mm roll (~48 of 58): the right-hand end of every line, amounts included, was cut off
 * instead of wrapping. The geometry now lives in receipts/_paper.blade.php. CSS cannot be unit-tested for
 * real, so these pin the structure; the clipping itself was measured in Chromium (see the commit message).
 */
class ReceiptPrintableWidthTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        $this->cashier = User::factory()->create();
        $shift = Shift::create(['user_id' => $this->cashier->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);
        $vat = Tax::firstOrCreate(['name' => 'Lebanese VAT'], ['rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $product = Product::factory()->create([
            'name' => 'Extra Virgin Cold Pressed Organic Olive Oil From The Hills Of South Lebanon 5 Litre Tin Container',
            'name_ar' => 'زيت زيتون بكر ممتاز معصور على البارد عضوي من تلال الجنوب اللبناني عبوة صفيحة خمسة ليترات',
            'price_usd' => 5, 'stock_qty' => 10, 'tax_id' => $vat->id, 'is_taxable' => true,
        ]);
        $this->sale = app(SaleService::class)->process(
            cart: [['product_id' => $product->id, 'qty' => 2]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 20.00],
            user: $this->cashier, shiftId: $shift->id,
        );
    }

    /** @return array<string, array{int, int, string}> paper => [paper, printable, language] */
    public static function papers(): array
    {
        $cases = [];
        foreach ([[58, 48], [80, 72]] as [$paper, $printable]) {
            foreach (['en', 'ar', 'bilingual'] as $lang) {
                $cases["{$paper}mm {$lang}"] = [$paper, $printable, $lang];
            }
        }

        return $cases;
    }

    private function css(string $html): string
    {
        preg_match('/<style>(.*?)<\/style>/s', $html, $m);

        return preg_replace('~/\*.*?\*/~s', '', $m[1] ?? '');   // comments out, so they cannot hide or fake a rule
    }

    #[DataProvider('papers')]
    public function test_the_page_is_laid_out_in_the_printable_width_not_the_paper_width(int $paper, int $printable, string $lang): void
    {
        Setting::set('receipt_width', $paper, 'receipt', 'int');
        Setting::set('receipt_language', $lang, 'receipt');

        $html = $this->actingAs($this->cashier)->get("/pos/receipts/{$this->sale->id}/print?format=thermal&auto=0")->assertOk()->getContent();
        $css = $this->css($html);

        $this->assertStringContainsString(":root { --rw: {$printable}mm; }", $css);          // one place, derived from receipt_width
        $this->assertStringContainsString('@page { margin: 0; }', $css);
        $this->assertStringNotContainsString('@page { size', $css);                          // `80mm auto` is invalid CSS and was dropped
        $this->assertMatchesRegularExpression('/body \{[^}]*width: auto; max-width: var\(--rw\);[^}]*overflow-wrap: anywhere;/', $css);
        $this->assertDoesNotMatchRegularExpression("/[^-]width:\s*{$paper}mm/", $css);          // no body as wide as the whole roll
        $this->assertStringNotContainsString('overflow: hidden', $css);                      // nothing may hide what does not fit
    }

    #[DataProvider('papers')]
    public function test_only_amounts_may_refuse_to_wrap(int $paper, int $printable, string $lang): void
    {
        Setting::set('receipt_width', $paper, 'receipt', 'int');
        Setting::set('receipt_language', $lang, 'receipt');

        $css = $this->css($this->actingAs($this->cashier)->get("/pos/receipts/{$this->sale->id}/print?format=thermal&auto=0")->getContent());

        // Every rule that sets nowrap, by selector: a long header, footer, name or customer must wrap.
        preg_match_all('/([^{}]+)\{[^}]*white-space:\s*nowrap[^}]*\}/', $css, $m);
        $this->assertSame(['.amt'], array_map('trim', $m[1]));
        $this->assertStringNotContainsString('white-space: pre', $css);
        $this->assertSame(1, preg_match('/\.amt\s*\{[^}]*direction: ltr/', $css), 'amounts stay left-to-right and on the end edge');
    }

    public function test_the_printable_width_is_one_value_per_paper_in_config(): void
    {
        $this->assertSame(['80' => 72, '58' => 48], array_map('intval', config('pos.receipt.printable_mm')));
        $this->assertSame(72, ReceiptPrintService::printableMm(80));
        $this->assertSame(48, ReceiptPrintService::printableMm(58));
        $this->assertSame(72, ReceiptPrintService::printableMm(0), 'an unknown paper reads as 80');

        config(['pos.receipt.printable_mm' => [80 => 70, 58 => 46]]);                       // a different printer, one edit
        Setting::set('receipt_width', 58, 'receipt', 'int');
        $this->actingAs($this->cashier)->get("/pos/receipts/{$this->sale->id}/print?format=thermal&auto=0")->assertSee('--rw: 46mm', false);
    }

    public function test_the_test_print_and_the_silent_print_use_the_same_geometry(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/settings/receipt/test-print', ['group' => 'receipt', 'settings' => ['receipt_width' => 58, 'receipt_header' => str_repeat('A very long typed header ', 8)]])
            ->assertOk()->assertSee('--rw: 48mm', false)->assertSee('max-width: var(--rw)', false);

        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:4000/api/']);
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        Setting::set('receipt_width', 80, 'receipt', 'int');
        $this->actingAs($this->cashier)->postJson("/pos/receipts/{$this->sale->id}/print-thermal")->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_contains(rawurldecode($r['html']), '--rw: 72mm') && ! str_contains(rawurldecode($r['html']), '@page { size'));
    }

    public function test_the_copy_test_and_demo_banners_are_ordinary_wrapping_blocks(): void
    {
        $html = $this->actingAs($this->cashier)->get("/pos/receipts/{$this->sale->id}/print?format=thermal&auto=0&copy=1")->assertOk()->getContent();

        $this->assertStringContainsString('<div class="center bold">*** COPY', $html);
        $this->assertStringContainsString('class="center bold" dir="rtl"', $html);
    }

    public function test_the_a4_invoice_keeps_its_own_page(): void
    {
        $html = $this->actingAs($this->cashier)->get("/pos/receipts/{$this->sale->id}/print?format=a4&auto=0")->assertOk()->getContent();

        $this->assertStringContainsString('@page { size: A4; margin: 12mm; }', $html);
        $this->assertStringNotContainsString('--rw', $html);
    }
}
