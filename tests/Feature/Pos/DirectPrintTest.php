<?php

namespace Tests\Feature\Pos;

use App\Models\AuditLog;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Tax;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Receipts print straight to a printer inside the desktop app and never open a window or the
 * system dialog; a browser tab keeps the popup. The A4 half of this lives in InvoiceA4Test.
 */
class DirectPrintTest extends TestCase
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

    private function inDesktopApp(): void
    {
        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:4000/api/']);
    }

    private function sale(): Sale
    {
        $vat = Tax::firstOrCreate(['name' => 'Lebanese VAT'], ['rate' => 0.11, 'is_inclusive' => false, 'is_default' => true, 'is_active' => true]);
        $product = Product::factory()->create(['name' => 'Test Item', 'name_ar' => 'صنف', 'price_usd' => 5, 'stock_qty' => 10, 'tax_id' => $vat->id, 'is_taxable' => true]);

        return app(SaleService::class)->process(
            cart: [['product_id' => $product->id, 'qty' => 2]],
            payment: ['method' => 'cash_usd', 'amount_usd' => 20.00],
            user: $this->cashier, shiftId: $this->shift->id,
        );
    }

    private function printThermal(Sale $sale, string $query = '', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->cashier)->postJson("/pos/receipts/{$sale->id}/print-thermal{$query}");
    }

    // ---- who may, and the browser fallback ---------------------------------------

    public function test_only_the_cashier_who_made_the_sale_or_a_manager_may_print_it(): void
    {
        $this->inDesktopApp();
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        $sale = $this->sale();

        $other = User::factory()->create();
        $this->printThermal($sale, '', $other)->assertForbidden();
        $this->printThermal($sale, '', User::factory()->manager()->create())->assertOk();
        Http::assertSentCount(1);
    }

    public function test_a_guest_cannot_print(): void
    {
        $sale = $this->sale();

        $this->postJson("/pos/receipts/{$sale->id}/print-thermal")->assertUnauthorized();
    }

    public function test_outside_the_desktop_app_the_endpoint_says_to_use_the_popup_and_sends_nothing(): void
    {
        Http::fake();
        Setting::set('thermal_printer_name', 'EPSON TM-T20', 'receipt');
        $sale = $this->sale();

        $this->printThermal($sale)->assertOk()->assertExactJson(['printed' => false, 'desktop' => false]);
        Http::assertNothingSent();
    }

    // ---- the desktop app: silent, to the named printer or the default one ----------

    public function test_a_named_printer_gets_the_receipt_with_no_dialog_and_no_margins(): void
    {
        $this->inDesktopApp();
        Setting::set('thermal_printer_name', 'EPSON TM-T20', 'receipt');
        Setting::set('receipt_width', 58, 'receipt', 'int');
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        $sale = $this->sale();

        $this->printThermal($sale)->assertOk()->assertExactJson(['printed' => true, 'desktop' => true]);

        Http::assertSent(function (HttpRequest $request) use ($sale) {
            $html = rawurldecode($request['html']);

            return str_ends_with($request->url(), '/system/print')
                && $request['printer'] === 'EPSON TM-T20'
                && $request['settings']['silent'] === true
                && $request['settings']['margins'] === ['marginType' => 'none']
                && ! isset($request['settings']['header']) && ! isset($request['settings']['footer'])   // no browser header/footer
                && ! isset($request['settings']['pageSize'])                                           // the roll driver sizes the page
                && ! str_contains($request['html'], '#')                                               // percent-encoded: a bare # would cut a data: URL short
                && str_contains($html, '@page { size: 58mm auto; margin: 0; }')                        // width follows receipt_width...
                && str_contains($html, 'width: 58mm')
                && str_contains($html, 'box-sizing: border-box')                                       // ...padding included
                && str_contains($html, $sale->receipt_number)
                && ! str_contains($html, 'window.print()')                                             // the app prints it; the page must not
                && ! str_contains($html, 'class="actions"')
                && ! str_contains($html, 'fonts/cairo')                                                // nothing fetched from a data: origin
                && substr_count($html, 'data:font/woff2;base64,') === 4;                                // Cairo (Arabic + Latin, 2 weights) inline
        });
    }

    public function test_the_80mm_setting_prints_on_80mm(): void
    {
        $this->inDesktopApp();
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        Setting::set('receipt_width', 80, 'receipt', 'int');

        $this->printThermal($this->sale())->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_contains(rawurldecode($r['html']), '@page { size: 80mm auto; margin: 0; }'));
    }

    public function test_with_no_printer_chosen_the_receipt_goes_to_the_windows_default_printer(): void
    {
        $this->inDesktopApp();
        Http::fake(['*/system/print' => Http::response('OK', 200)]);

        $this->printThermal($this->sale())->assertOk()->assertExactJson(['printed' => true, 'desktop' => true]);

        // No printer key at all, so Electron uses its default instead of a device called "".
        Http::assertSent(fn (HttpRequest $r) => ! array_key_exists('printer', $r->data()) && $r['settings']['silent'] === true);
    }

    public function test_an_arabic_receipt_carries_its_own_font_files(): void
    {
        $this->inDesktopApp();
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        Setting::set('receipt_language', 'ar', 'receipt');

        $this->printThermal($this->sale())->assertOk();

        Http::assertSent(function (HttpRequest $r) {
            $html = rawurldecode($r['html']);
            $arabic = 'data:font/woff2;base64,'.base64_encode(file_get_contents(public_path('fonts/cairo-arabic-400-normal.woff2')));

            return str_contains($html, 'dir="rtl"') && str_contains($html, $arabic) && str_contains($html, "font-family: 'Cairo'");
        });
    }

    public function test_a_premium_custom_template_prints_silently_too(): void
    {
        $this->inDesktopApp();
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        PrintTemplate::create([
            'name' => 'Mine', 'type' => 'receipt', 'is_active' => true, 'is_default' => true,
            'template_html' => '<html><body><p>MINE {{ receipt_number }}</p></body></html>',
        ]);
        $sale = $this->sale();

        $this->printThermal($sale)->assertOk()->assertExactJson(['printed' => true, 'desktop' => true]);

        Http::assertSent(fn (HttpRequest $r) => str_contains(rawurldecode($r['html']), 'MINE '.$sale->receipt_number) && ! str_contains(rawurldecode($r['html']), 'window.print();'));
    }

    // ---- failure: the sale stays, the page shows Retry ---------------------------

    public function test_a_failed_print_is_a_502_that_leaves_the_sale_and_the_audit_log_alone(): void
    {
        $this->inDesktopApp();
        Http::fake(['*/system/print' => Http::sequence()->push('', 500)->push('OK', 200)]);   // refuses once, then works
        $sale = $this->sale();

        $this->printThermal($sale, '?copy=1&src=history')->assertStatus(502)->assertExactJson(['printed' => false, 'desktop' => true]);

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'receipt_reprint')->count());

        // Retry once the printer is back: that one is the logged reprint.
        $this->printThermal($sale, '?copy=1&src=history')->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'receipt_reprint')->count());
    }

    public function test_an_unreachable_desktop_shell_is_a_failed_print_not_an_exception(): void
    {
        // Nothing listens on the discard port: a genuine, instant connection refusal.
        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:9/api/']);

        $this->printThermal($this->sale())->assertStatus(502)->assertExactJson(['printed' => false, 'desktop' => true]);
    }

    // ---- reprints stay audited ----------------------------------------------------

    public function test_a_silent_reprint_is_audited_and_the_sell_screens_first_print_is_not(): void
    {
        $this->inDesktopApp();
        Http::fake(['*/system/print' => Http::response('OK', 200)]);
        $manager = User::factory()->manager()->create();
        $sale = $this->sale();

        $this->printThermal($sale)->assertOk();                                            // the sell screen's own print
        $this->assertSame(0, AuditLog::where('action', 'receipt_reprint')->count());

        $this->printThermal($sale, '?copy=1&src=history', $manager)->assertOk();
        $log = AuditLog::where('action', 'receipt_reprint')->sole();
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame($sale->id, $log->model_id);
        $this->assertSame(['receipt' => $sale->receipt_number, 'format' => 'thermal', 'source' => 'history'], $log->new_values);

        // The silent page carries the COPY stamp, too.
        Http::assertSent(fn (HttpRequest $r) => str_contains(rawurldecode($r['html']), 'COPY') && str_contains(rawurldecode($r['html']), 'نسخة'));
    }

    // ---- the screens ----------------------------------------------------------------

    public function test_the_sell_screen_knows_the_desktop_flag_the_autoprint_setting_and_has_the_retry_banner(): void
    {
        Setting::set('auto_print', '0', 'pos', 'bool');
        $this->actingAs($this->cashier)->get('/pos')->assertOk()
            ->assertSee('desktop: false', false)->assertSee('autoPrint: false', false)
            ->assertSee('retryPrint()', false)->assertSee('role="alert"', false)
            ->assertSee('The sale is completed, but the receipt could not be printed')->assertSee('Retry');

        Setting::set('auto_print', '1', 'pos', 'bool');
        Setting::set('receipt_format', 'ask', 'receipt');
        $this->inDesktopApp();
        $this->actingAs($this->cashier)->get('/pos')->assertOk()
            ->assertSee('desktop: true', false)->assertSee('autoPrint: true', false)
            // "ask" is an in-page chooser, not a system dialog: both formats stay one click away
            ->assertSee("printLast('thermal')", false)->assertSee("printLast('a4')", false)->assertSee('Choose how to print this sale');
    }

    public function test_the_sell_screen_never_opens_a_window_from_the_desktop_path(): void
    {
        $this->inDesktopApp();
        $html = $this->actingAs($this->cashier)->get('/pos')->assertOk()->getContent();

        // window.open lives only in _printReceipt, which _print() reaches only when the page is not the desktop app.
        $print = substr($html, strpos($html, 'async _print('), strpos($html, '_printReceipt(saleId, format =') - strpos($html, 'async _print('));
        $this->assertStringContainsString('if (!this.desktop) return this._printReceipt', $print);
        $this->assertStringContainsString('this.printError = { saleId, format }', $print);
        $this->assertStringNotContainsString('window.open', $print);
        $this->assertSame(2, substr_count($print, '_printReceipt('));   // both behind "not the desktop app" / "server says so"
    }

    public function test_the_sell_screen_retry_text_is_arabic_for_an_arabic_cashier(): void
    {
        $arabic = User::factory()->create(['language' => 'ar']);
        Shift::create(['user_id' => $arabic->id, 'opened_at' => now(), 'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN]);

        $json = fn (string $text) => trim(json_encode($text), '"');
        $this->actingAs($arabic)->get('/pos')->assertOk()
            ->assertSee($json('إعادة المحاولة'), false)->assertSee($json('تمت عملية البيع، لكن تعذّرت طباعة الإيصال'), false);
    }

    public function test_sales_history_reprints_silently_in_the_desktop_app_and_shows_a_retry_on_failure(): void
    {
        $this->inDesktopApp();
        $manager = User::factory()->manager()->create();
        $sale = $this->sale();

        $this->actingAs($manager)->get('/sales')->assertOk()
            ->assertSee('salesReprint(true)', false)->assertSee('x-show="failed"', false)
            ->assertSee('The receipt could not be printed')->assertSee('Retry');
        $this->actingAs($manager)->get("/sales/{$sale->id}")->assertOk()
            ->assertSee('salesReprint(true)', false)->assertSee('x-show="failed"', false);
    }

    public function test_sales_history_keeps_the_popup_in_a_browser(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/sales')->assertOk()->assertSee('salesReprint(false)', false);
    }

    // ---- Settings -----------------------------------------------------------------------

    public function test_the_receipt_tab_saves_and_validates_the_receipt_printer(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'receipt', 'settings' => ['thermal_printer_name' => 'EPSON TM-T20', 'receipt_width' => 58],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('EPSON TM-T20', Setting::get('thermal_printer_name'));

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'receipt', 'settings' => ['thermal_printer_name' => '', 'receipt_width' => 80]])
            ->assertSessionHasNoErrors();
        $this->assertSame('', Setting::get('thermal_printer_name'));

        $this->actingAs($admin)->post(route('settings.update'), ['group' => 'receipt', 'settings' => ['thermal_printer_name' => str_repeat('x', 256), 'receipt_width' => 80]])
            ->assertSessionHasErrors('settings.thermal_printer_name');
        $this->assertSame('', Setting::get('thermal_printer_name'));
    }

    public function test_the_receipt_tab_labels_the_empty_choice_as_the_default_windows_printer(): void
    {
        $admin = User::factory()->admin()->create();
        $this->inDesktopApp();
        Http::fake(['*/system/printers' => Http::response(['printers' => [['name' => 'EPSON TM-T20', 'displayName' => 'Epson Receipt']]])]);

        $this->actingAs($admin)->get('/settings?tab=receipt')->assertOk()
            ->assertSee('Receipt printer')
            ->assertSee('<select name="settings[thermal_printer_name]"', false)
            ->assertSee('Default Windows printer')->assertSee('Epson Receipt')
            ->assertDontSee('Ask each time');
    }

    public function test_the_test_print_goes_to_the_printer_silently_in_the_desktop_app(): void
    {
        $this->inDesktopApp();
        Http::fake(['*/system/print' => Http::sequence()->push('OK', 200)->push('', 500)]);
        Setting::set('thermal_printer_name', 'EPSON TM-T20', 'receipt');
        $admin = User::factory()->admin()->create();
        $auditsBefore = AuditLog::count();

        $this->actingAs($admin)->postJson('/settings/receipt/test-print', ['group' => 'receipt', 'settings' => ['receipt_width' => 58, 'receipt_header' => 'Typed header']])
            ->assertOk()->assertExactJson(['printed' => true, 'desktop' => true]);

        Http::assertSent(fn (HttpRequest $r) => $r['printer'] === 'EPSON TM-T20'
            && str_contains(rawurldecode($r['html']), 'TEST PRINT')
            && str_contains(rawurldecode($r['html']), 'Typed header')
            && str_contains(rawurldecode($r['html']), 'size: 58mm')
            && ! str_contains(rawurldecode($r['html']), 'window.print()'));
        $this->assertSame($auditsBefore, AuditLog::count());

        $this->actingAs($admin)->postJson('/settings/receipt/test-print', ['group' => 'receipt', 'settings' => ['receipt_width' => 80]])
            ->assertStatus(502)->assertExactJson(['printed' => false, 'desktop' => true]);
    }

    public function test_the_test_print_still_opens_the_page_in_a_browser(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/settings/receipt/test-print', ['group' => 'receipt', 'settings' => ['receipt_width' => 80]])
            ->assertOk()->assertSee('TEST PRINT')->assertSee("addEventListener('load'", false);
    }
}
