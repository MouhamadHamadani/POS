<?php

namespace Tests\Feature\I18n;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each report exports a real PDF while the UI is Arabic and the data has Arabic names. */
class ReportPdfArabicTest extends TestCase
{
    use RefreshDatabase;

    private function seedSales(User $user): void
    {
        $shift = Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $products = [
            Product::factory()->create(['name' => 'Olive Oil 1L Al Wadi', 'name_ar' => 'زيت زيتون 1L Al Wadi', 'stock_qty' => 12, 'min_stock' => 5, 'unit' => 'pcs']),
            Product::factory()->create(['name' => 'Tea 25 bags', 'name_ar' => 'شاي 25 كيس', 'stock_qty' => 0, 'min_stock' => 5, 'unit' => 'pcs']),
        ];
        foreach ($products as $i => $product) {
            $sale = Sale::create([
                'receipt_number' => 'REC-PDF-'.($i + 1), 'user_id' => $user->id, 'shift_id' => $shift->id,
                'subtotal_usd' => 10, 'discount_amount_usd' => 0, 'tax_amount_usd' => 1.10, 'total_usd' => 11.10,
                'total_lbp' => 999000, 'exchange_rate' => 90000, 'payment_method' => Sale::METHOD_CASH_USD,
                'status' => Sale::STATUS_COMPLETED,
            ]);
            SaleItem::create([
                'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => $product->name, 'qty' => 2,
                'unit_price_usd' => 5, 'cost_usd' => 2, 'line_total_usd' => 11.10, 'tax_rate' => 0.11, 'tax_amount_usd' => 1.10,
            ]);
        }
    }

    public static function reports(): array
    {
        return [
            'daily sales' => ['/reports/sales/daily'],
            'sales by product' => ['/reports/sales/by-product'],
            'stock levels' => ['/reports/inventory/stock-levels'],
            'inventory by date' => ['/reports/inventory/by-date'],
            'profit and loss' => ['/reports/financial/pnl'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reports')]
    public function test_every_report_exports_a_pdf_in_arabic(string $url): void
    {
        $manager = User::factory()->manager()->create(['language' => 'ar']);
        $this->seedSales($manager);

        $response = $this->actingAs($manager)->get($url.'?format=pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        // the file name stays ASCII whatever the UI language
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $response->headers->get('content-disposition'));
    }

    public function test_the_arabic_pdf_html_is_shaped_rtl_and_columns_are_reversed(): void
    {
        $manager = User::factory()->manager()->create(['language' => 'ar']);
        $this->seedSales($manager);
        $data = app(\App\Services\ReportService::class)->stockLevels();

        app()->setLocale('ar');
        $html = view('reports.stock-levels-pdf', $data + ['title' => 'مستويات المخزون'])->render();

        $this->assertStringContainsString("font-family: 'Amiri'", $html);
        $this->assertStringContainsString('text-align: right', $html);
        // the title reaches dompdf joined and in visual order, not as the logical string
        // (letters that cannot join keep their base code point, so "no raw letters" is the wrong check)
        $this->assertStringNotContainsString('مستويات المخزون', $html);
        $this->assertMatchesRegularExpression('/[\x{FE70}-\x{FEFF}]/u', $html);
        // columns are emitted in reverse for RTL: the status column comes first
        $this->assertLessThan(strpos($html, 'SKU'), strpos($html, 'class="num"'));
    }

    public function test_the_english_pdf_html_is_unchanged_in_language_and_direction(): void
    {
        $data = app(\App\Services\ReportService::class)->stockLevels();

        app()->setLocale('en');
        $html = view('reports.stock-levels-pdf', $data + ['title' => 'Inventory Stock Levels'])->render();

        $this->assertStringContainsString("font-family: 'DejaVu Sans'", $html);
        $this->assertStringContainsString('Inventory Stock Levels', $html);
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $html);
    }
}
