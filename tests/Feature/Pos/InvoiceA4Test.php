<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
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
use Tests\TestCase;

/**
 * The A4 invoice as a second print format for the same sale: which format a request
 * gets, that the page shows the sale's stored figures, and how A4 reaches a printer.
 */
class InvoiceA4Test extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('lbp_rounding_step', 1000, 'currency', 'float');
        $this->cashier = User::factory()->create(['max_discount_pct' => 100]);
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
    }

    private function vat(): Tax
    {
        return Tax::firstOrCreate(['name' => 'Lebanese VAT'], ['rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
    }

    /** A plain 2 x 5.00 sale with 11% VAT, paid with 20.00 cash. */
    private function simpleSale(): Sale
    {
        $product = Product::factory()->create(['name' => 'Test Item', 'price_usd' => 5, 'stock_qty' => 10, 'tax_id' => $this->vat()->id, 'is_taxable' => true]);

        return app(SaleService::class)->process(
            cart: [['product_id' => $product->id, 'qty' => 2]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 20.00],
            user: $this->cashier, shiftId: $this->shift->id,
        );
    }

    /**
     * 3 x Rice @10 (no discount) + 1 x Oil @25 less 5.00, then 10% off the cart, 11% VAT:
     *   subtotal 55.00 | line discount 5.00 | cart discount 5.00 (rice 3.00, oil 2.00) | VAT 4.95 | total 49.95
     */
    private function discountedSale(?Customer $customer = null): Sale
    {
        $vat = $this->vat();
        $rice = Product::where('sku', 'RICE-1')->first() ?? Product::factory()->create(['name' => 'Rice 1kg', 'name_ar' => 'أرز 1 كلغ', 'sku' => 'RICE-1', 'unit' => 'kg', 'price_usd' => 10, 'cost_usd' => 4, 'stock_qty' => 100, 'tax_id' => $vat->id, 'is_taxable' => true]);
        $oil = Product::where('sku', 'OIL-1')->first() ?? Product::factory()->create(['name' => 'Olive Oil 1L', 'sku' => 'OIL-1', 'price_usd' => 25, 'cost_usd' => 10, 'stock_qty' => 100, 'tax_id' => $vat->id, 'is_taxable' => true]);

        return app(SaleService::class)->process(
            cart: [['product_id' => $rice->id, 'qty' => 3], ['product_id' => $oil->id, 'qty' => 1, 'discount_amount' => 5.00]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 60.00],
            user: $this->cashier, shiftId: $this->shift->id,
            customerId: $customer?->id,
            cartDiscount: ['pct' => 10],
        );
    }

    private function show(Sale $sale, string $query = '', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->cashier)->get("/pos/receipts/{$sale->id}/print{$query}");
    }

    // ---- which format ----------------------------------------------------------

    public function test_the_format_query_picks_the_view(): void
    {
        $sale = $this->simpleSale();

        $this->show($sale, '?format=a4')->assertOk()->assertViewIs('receipts.a4')->assertSee('Invoice', false)->assertSee('@page { size: A4; margin: 12mm; }', false);
        $this->show($sale, '?format=thermal')->assertOk()->assertViewIs('receipts.thermal')->assertDontSee('size: A4', false);
    }

    public function test_with_no_format_the_setting_decides_and_ask_means_thermal(): void
    {
        $sale = $this->simpleSale();

        $this->show($sale)->assertViewIs('receipts.thermal');                       // setting never saved

        foreach (['thermal' => 'receipts.thermal', 'a4' => 'receipts.a4', 'ask' => 'receipts.thermal'] as $setting => $view) {
            Setting::set('receipt_format', $setting, 'receipt');
            $this->show($sale)->assertViewIs($view);
            $this->show($sale, '?auto=1')->assertViewIs($view);
        }

        // an explicit format always beats the setting; nonsense is ignored
        Setting::set('receipt_format', 'a4', 'receipt');
        $this->show($sale, '?format=thermal')->assertViewIs('receipts.thermal');
        Setting::set('receipt_format', 'thermal', 'receipt');
        $this->show($sale, '?format=a4')->assertViewIs('receipts.a4');
        $this->show($sale, '?format=pdf')->assertViewIs('receipts.thermal');
        Setting::set('receipt_format', 'nonsense', 'receipt');
        $this->show($sale)->assertViewIs('receipts.thermal');
    }

    public function test_a_cashier_cannot_print_another_cashiers_sale_in_either_format(): void
    {
        $sale = $this->simpleSale();
        $other = User::factory()->create();

        $this->show($sale, '?format=a4', $other)->assertForbidden();
        $this->show($sale, '?format=thermal', $other)->assertForbidden();
        $this->show($sale, '', $other)->assertForbidden();
        $this->actingAs($other)->postJson("/pos/receipts/{$sale->id}/print-a4")->assertForbidden();

        $manager = User::factory()->manager()->create();
        $this->show($sale, '?format=a4', $manager)->assertOk();
        $this->show($sale, '?format=thermal', $manager)->assertOk();
        $this->show($sale, '?format=a4', $this->cashier)->assertOk();     // the owner still can
    }

    public function test_the_thermal_receipt_is_untouched(): void
    {
        $sale = $this->simpleSale();

        $this->show($sale, '?format=thermal')->assertOk()
            ->assertSee('REC-', false)->assertSee('TOTAL')->assertSee('$11.10')->assertSee('Change USD')
            ->assertSee('size: 80mm auto', false)
            ->assertDontSee('data-part="top"', false);
    }

    // ---- the figures ------------------------------------------------------------

    public function test_the_a4_totals_are_the_stored_sale_totals(): void
    {
        $sale = $this->discountedSale();

        // the sale itself, hand-computed above
        $this->assertEqualsWithDelta(55.00, (float) $sale->subtotal_usd, 0.0001);
        $this->assertEqualsWithDelta(10.00, (float) $sale->discount_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(5.00, (float) $sale->cart_discount_usd, 0.0001);
        $this->assertEqualsWithDelta(4.95, (float) $sale->tax_amount_usd, 0.0001);
        $this->assertEqualsWithDelta(49.95, (float) $sale->total_usd, 0.0001);

        $page = $this->show($sale, '?format=a4&auto=0')->assertOk();
        $page->assertSee('$55.00')                                   // subtotal
            ->assertSee('Item discounts')->assertSee('Cart discount')
            ->assertSee('VAT (11%)')->assertSee('$4.95')
            ->assertSee('$49.95')                                    // total USD
            ->assertSee(number_format((float) $sale->total_lbp))     // total LBP, as stored
            ->assertSee('Cash USD tendered')->assertSee('$60.00')
            ->assertSee('Change USD')->assertSee('$10.05')
            ->assertSee('Exchange rate')->assertSee('1 USD = 90,000 LBP')
            ->assertSee('Total (LBP)');

        // item discounts (5.00) and the cart discount (5.00) are shown apart, never added twice
        $html = $page->getContent();
        $this->assertSame(2, substr_count($html, '-$5.00'));
        $this->assertStringNotContainsString('-$10.00', $html);

        // the line column carries the stored line discounts (cart share allocated in) and totals
        $page->assertSee('-$3.00')->assertSee('-$7.00')->assertSee('$29.97')->assertSee('$19.98');
        $this->assertEqualsWithDelta((float) $sale->total_usd, (float) $sale->items->sum('line_total_usd'), 0.0001);
    }

    public function test_a_reprint_shows_the_original_figures_even_after_prices_and_tax_change(): void
    {
        $sale = $this->discountedSale();
        $before = $this->show($sale, '?format=a4&auto=0')->getContent();

        Product::query()->update(['price_usd' => 999, 'cost_usd' => 500, 'name' => 'Renamed']);
        Tax::query()->update(['rate' => 0.5]);
        Setting::set('exchange_rate', 123456, 'currency', 'float');

        $page = $this->show($sale, '?format=a4&auto=0');
        $page->assertSee('$49.95')->assertSee('$4.95')->assertSee('1 USD = 90,000 LBP')->assertDontSee('999.00');
        $this->assertSame(
            $this->figures($before),
            $this->figures($page->getContent()),
        );
    }

    /** Every $-amount on the page, in order. */
    private function figures(string $html): array
    {
        preg_match_all('/-?\$\d[\d,]*\.\d{2}/', $html, $m);

        return $m[0];
    }

    public function test_the_discount_column_only_appears_when_a_line_has_a_discount(): void
    {
        $this->show($this->simpleSale(), '?format=a4&auto=0')->assertOk()->assertDontSee('Disc.');
        $this->show($this->discountedSale(), '?format=a4&auto=0')->assertOk()->assertSee('Disc.');
    }

    public function test_lines_show_code_name_quantity_with_unit_and_unit_price(): void
    {
        $sale = $this->discountedSale();

        $this->show($sale, '?format=a4&auto=0')->assertOk()
            ->assertSee('RICE-1')->assertSee('OIL-1')
            ->assertSee('Rice 1kg')->assertSee('Olive Oil 1L')
            ->assertSee('3 kg')                       // qty with unit
            ->assertSee('$10.00')->assertSee('$25.00')
            ->assertDontSee('أرز 1 كلغ');             // English UI: the English name
    }

    public function test_the_customer_block_only_shows_for_a_sale_with_a_customer(): void
    {
        $this->show($this->simpleSale(), '?format=a4&auto=0')->assertOk()->assertDontSee('class="customer', false);

        $customer = Customer::create(['name' => 'Rami Haddad', 'phone' => '03 123 456', 'address' => 'Hamra, Beirut']);
        $this->show($this->discountedSale($customer), '?format=a4&auto=0')->assertOk()
            ->assertSee('class="customer', false)->assertSee('Rami Haddad')->assertSee('03 123 456')->assertSee('Hamra, Beirut');

        $bare = Customer::create(['name' => 'Walk-in Regular']);
        $this->show($this->discountedSale($bare), '?format=a4&auto=0')->assertOk()
            ->assertSee('Walk-in Regular')->assertDontSee('Phone');
    }

    public function test_the_header_and_footer_come_from_settings(): void
    {
        Setting::set('business_name', 'My Test Shop', 'general');
        Setting::set('business_name_ar', 'متجري', 'general');
        Setting::set('address', '12 Hamra St', 'general');
        Setting::set('phone', '01 234 567', 'general');
        Setting::set('tax_number', 'LB-VAT-12345', 'general');
        $sale = $this->simpleSale();

        $this->show($sale, '?format=a4&auto=0')->assertOk()
            ->assertSee('My Test Shop')->assertSee('متجري')->assertSee('12 Hamra St')->assertSee('01 234 567')->assertSee('LB-VAT-12345')
            ->assertSee('Invoice /', false)->assertSee('فاتورة')
            ->assertSee($sale->receipt_number)
            ->assertSee('Thank you for your business!');

        Setting::set('receipt_footer', 'Come again soon!', 'receipt');
        $this->show($sale, '?format=a4&auto=0')->assertSee('Come again soon!')->assertDontSee('Thank you for your business!');
    }

    public function test_it_is_bilingual_and_runs_right_to_left_in_an_arabic_ui(): void
    {
        $sale = $this->discountedSale();
        $arabic = User::factory()->manager()->create(['language' => 'ar']);

        $page = $this->show($sale, '?format=a4&auto=0', $arabic);
        $page->assertOk()
            ->assertSee('<html lang="ar" dir="rtl"', false)
            ->assertSee('أرز 1 كلغ')                    // localName
            ->assertSee('فاتورة')->assertSee('Invoice')  // labels are always both
            ->assertSee(trim(json_encode('صفحة :n من :total'), '"'), false);   // the page counter template, localized

        $this->show($sale, '?format=a4&auto=0')->assertSee('<html lang="en" dir="ltr"', false)->assertSee('Page :n of :total', false);
    }

    public function test_the_page_repeats_the_header_and_numbers_its_pages(): void
    {
        $this->show($this->simpleSale(), '?format=a4&auto=0')->assertOk()
            ->assertSee('table.items thead { display: table-header-group; }', false)   // header row repeats on every printed page
            ->assertSee("document.getElementById('invoice')", false)                   // the paginator
            ->assertSee('break-after: page', false);
    }

    public function test_auto_print_follows_the_auto_query_like_the_thermal_page(): void
    {
        $sale = $this->simpleSale();

        $this->show($sale, '?format=a4&auto=1')->assertSee('window.print()', false)->assertSee("addEventListener('load'", false);
        $this->show($sale, '?format=a4&auto=0')->assertDontSee("addEventListener('load'", false);
    }

    public function test_a_demo_build_stamps_the_invoice_so_it_cannot_pass_as_a_record(): void
    {
        config(['pos.demo_mode' => true]);

        $this->show($this->simpleSale(), '?format=a4&auto=0')->assertOk()->assertSee('DEMO RECEIPT')->assertSee('class="notice"', false);
    }

    // ---- getting A4 to a printer ---------------------------------------------------

    private function inDesktopApp(): void
    {
        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:4000/api/']);
    }

    public function test_silent_printing_needs_the_desktop_app_and_a_chosen_printer(): void
    {
        $prints = app(ReceiptPrintService::class);
        $this->assertFalse($prints->silentA4Available());

        Setting::set('a4_printer_name', 'HP LaserJet', 'receipt');
        $this->assertFalse($prints->silentA4Available());       // printer set, but a browser tab can't do it

        $this->inDesktopApp();
        $this->assertTrue($prints->silentA4Available());

        Setting::set('a4_printer_name', '   ', 'receipt');
        $this->assertFalse($prints->silentA4Available());       // no printer: the print dialog decides
    }

    public function test_without_silent_printing_the_endpoint_says_nothing_was_printed(): void
    {
        Http::fake();
        $sale = $this->simpleSale();

        $this->actingAs($this->cashier)->postJson("/pos/receipts/{$sale->id}/print-a4")
            ->assertOk()->assertExactJson(['printed' => false]);
        Http::assertNothingSent();
    }

    public function test_a_chosen_printer_gets_the_invoice_with_no_dialog(): void
    {
        $this->inDesktopApp();
        Setting::set('a4_printer_name', 'HP LaserJet', 'receipt');
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        $sale = $this->discountedSale();

        $this->actingAs($this->cashier)->postJson("/pos/receipts/{$sale->id}/print-a4")
            ->assertOk()->assertExactJson(['printed' => true]);

        Http::assertSent(function (HttpRequest $request) use ($sale) {
            $html = rawurldecode($request['html']);

            return str_ends_with($request->url(), '/system/print')
                && $request['printer'] === 'HP LaserJet'
                && $request['settings']['silent'] === true
                && $request['settings']['pageSize'] === 'A4'
                && ! str_contains($request['html'], '#')              // percent-encoded: a bare # would cut a data: URL short
                && str_contains($html, $sale->receipt_number)
                && str_contains($html, '$49.95')
                && ! str_contains($html, 'window.print()')            // the app prints it; the page must not print itself
                && ! str_contains($html, 'class="actions"')           // no toolbar on paper
                && ! str_contains($html, 'fonts/cairo');              // nothing fetched from a data: origin
        });
    }

    public function test_a_printer_that_refuses_reports_failure_so_the_dialog_opens(): void
    {
        $this->inDesktopApp();
        Setting::set('a4_printer_name', 'Gone Printer', 'receipt');
        Http::fake(['*/system/print' => Http::response('', 500)]);
        $sale = $this->simpleSale();

        $this->actingAs($this->cashier)->postJson("/pos/receipts/{$sale->id}/print-a4")->assertOk()->assertExactJson(['printed' => false]);
    }

    public function test_an_unreachable_desktop_shell_reports_failure_too(): void
    {
        // Nothing listens on the discard port: a genuine, instant connection refusal.
        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:9/api/']);
        Setting::set('a4_printer_name', 'HP LaserJet', 'receipt');
        $sale = $this->simpleSale();

        $this->actingAs($this->cashier)->postJson("/pos/receipts/{$sale->id}/print-a4")->assertOk()->assertExactJson(['printed' => false]);
    }

    public function test_the_sell_screen_gets_the_format_and_whether_a4_prints_directly(): void
    {
        $this->actingAs($this->cashier)->get('/pos')->assertOk()
            ->assertSee('receiptFormat: "thermal"', false)->assertSee('a4Silent: false', false);

        Setting::set('receipt_format', 'ask', 'receipt');
        Setting::set('a4_printer_name', 'HP LaserJet', 'receipt');
        $this->inDesktopApp();
        $this->actingAs($this->cashier)->get('/pos')->assertOk()
            ->assertSee('receiptFormat: "ask"', false)->assertSee('a4Silent: true', false);
    }

    public function test_the_post_sale_panel_always_offers_both_formats_and_the_shortcuts(): void
    {
        foreach (['thermal', 'a4', 'ask'] as $format) {
            Setting::set('receipt_format', $format, 'receipt');
            $this->actingAs($this->cashier)->get('/pos')->assertOk()
                ->assertSee("printLast('thermal')", false)->assertSee("printLast('a4')", false)
                ->assertSee("e.shiftKey ? 'a4' : this.defaultFormat()", false)
                ->assertSee('Print receipt')->assertSee('Print A4')->assertSee('Small receipt')->assertSee('A4 invoice');
        }
    }

    public function test_the_sell_screen_strings_are_arabic_for_an_arabic_cashier(): void
    {
        $arabic = User::factory()->create(['language' => 'ar']);
        Shift::create(['user_id' => $arabic->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        // the sell screen hands its strings to Alpine as JSON, where Arabic comes out \u-escaped
        $json = fn (string $text) => trim(json_encode($text), '"');
        $this->actingAs($arabic)->get('/pos')->assertOk()
            ->assertSee($json('طباعة A4'), false)->assertSee($json('فاتورة A4'), false)->assertSee($json('طباعة الإيصال'), false);
    }

    // ---- Settings -------------------------------------------------------------------

    public function test_the_receipt_tab_saves_the_format_and_printer(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'receipt', 'settings' => ['receipt_format' => 'ask', 'a4_printer_name' => 'HP LaserJet', 'receipt_width' => 80],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ask', Setting::get('receipt_format'));
        $this->assertSame('HP LaserJet', Setting::get('a4_printer_name'));
        $this->assertSame(80, Setting::get('receipt_width'));

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'receipt', 'settings' => ['receipt_format' => 'a4', 'a4_printer_name' => '']]);
        $this->assertSame('a4', Setting::get('receipt_format'));
        $this->assertSame('', Setting::get('a4_printer_name'));

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'receipt', 'settings' => ['receipt_format' => 'fax']])
            ->assertSessionHasErrors('settings.receipt_format');
        $this->assertSame('a4', Setting::get('receipt_format'));
    }

    public function test_the_receipt_tab_lists_installed_printers_in_the_desktop_app_and_a_text_box_elsewhere(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('a4_printer_name', 'Old Printer', 'receipt');

        $this->actingAs($admin)->get('/settings?tab=receipt')->assertOk()
            ->assertSee('Print format')->assertSee('Small receipt (thermal)')->assertSee('A4 invoice')->assertSee('Ask after each sale')
            ->assertSee('name="settings[a4_printer_name]"', false)->assertSee('value="Old Printer"', false)
            ->assertSee('The list of installed printers appears in the desktop app');

        $this->inDesktopApp();
        Http::fake(['*/system/printers' => Http::response(['printers' => [
            ['name' => 'EPSON TM-T20', 'displayName' => 'Epson Receipt', 'isDefault' => true],
            ['name' => 'HP LaserJet', 'displayName' => 'HP LaserJet Pro'],
        ]])]);

        $this->actingAs($admin)->get('/settings?tab=receipt')->assertOk()
            ->assertSee('<select name="settings[a4_printer_name]"', false)
            ->assertSee('Ask each time (system print dialog)')
            ->assertSee('Epson Receipt')->assertSee('HP LaserJet Pro')
            ->assertSee('Old Printer');                               // a saved printer that is no longer listed stays selectable
    }

    public function test_the_new_settings_have_seeded_defaults(): void
    {
        $this->seed(\Database\Seeders\DefaultSettingsSeeder::class);

        $this->assertSame('thermal', Setting::get('receipt_format'));
        $this->assertSame('', Setting::get('a4_printer_name'));
    }
}
