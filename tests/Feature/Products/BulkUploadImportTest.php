<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tax;
use App\Models\User;
use App\Support\Spreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The import itself: parse, classify, and the all-or-nothing commit.
 * See BulkUploadAccessTest for who is allowed to get this far.
 */
class BulkUploadImportTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('bulk_upload_enabled_admin', '1', 'permissions', 'bool');
        $this->category = Category::factory()->create(['name' => 'Beverages']);
    }

    private function importer(): User
    {
        return User::factory()->admin()->create();
    }

    /** Build a CSV upload from rows, headings included. */
    private function csv(array $rows, array $headings = ['name', 'category', 'price_usd', 'barcode', 'sku']): UploadedFile
    {
        $lines = [implode(',', $headings)];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        $path = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        file_put_contents($path, implode("\n", $lines));

        return new UploadedFile($path, 'products.csv', 'text/csv', null, true);
    }

    private function preview(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post('/products/import/preview', ['file' => $file]);
    }

    // === Template ===

    public function test_template_downloads_in_a_format_this_runtime_can_write(): void
    {
        // NativePHP's bundled PHP has no ext-xmlwriter, so .xlsx cannot be
        // written in the packaged app at all — the extension follows the
        // runtime rather than being hardcoded. See App\Support\Spreadsheet.
        $this->actingAs($this->importer())
            ->get('/products/import/template')
            ->assertOk()
            ->assertDownload('product-import-template.' . Spreadsheet::extension());
    }

    public function test_the_template_round_trips_back_through_the_importer(): void
    {
        $user = $this->importer();

        // The example row names the category and tax it uses; both have to
        // resolve for it to be importable as shipped.
        Tax::factory()->create(['name' => 'VAT 11%']);

        $response = $this->actingAs($user)->get('/products/import/template')->assertOk();

        $file = tempnam(sys_get_temp_dir(), 'tpl') . '.' . Spreadsheet::extension();
        copy($response->baseResponse->getFile()->getPathname(), $file);

        // The shipped template's example row must be one the importer accepts —
        // a template that fails its own validation is worse than none.
        $this->preview($user, new UploadedFile($file, 'template.' . Spreadsheet::extension(), null, null, true))
            ->assertOk()
            ->assertSee('Coca Cola 1L');
    }

    // === Happy path ===

    public function test_valid_file_previews_then_creates_products(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv([
            ['Water 500ml', 'Beverages', '0.50', '1000000000009', 'W-500'],
            ['Juice 1L', 'Beverages', '2.25', '1000000000016', 'J-1L'],
        ]))->assertOk()->assertSee('Water 500ml');

        // Preview alone writes nothing.
        $this->assertSame(0, Product::count());

        $this->actingAs($user)->post('/products/import')
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('success', 'Imported 2 product(s).');

        $this->assertSame(2, Product::count());

        $water = Product::where('name', 'Water 500ml')->sole();
        $this->assertSame($this->category->id, $water->category_id);
        $this->assertSame('0.5000', $water->price_usd);
        $this->assertSame($user->id, $water->created_by);
    }

    public function test_blank_barcode_gets_one_generated(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv([['No Barcode', 'Beverages', '1.00', '', 'NB-1']]));
        $this->actingAs($user)->post('/products/import');

        $product = Product::sole();
        $this->assertSame(13, strlen($product->barcode));
    }

    public function test_blank_optional_columns_fall_back_to_model_defaults(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv(
            [['Sparse', 'Beverages', '3.00', '', '', '', '']],
            ['name', 'category', 'price_usd', 'barcode', 'sku', 'cost_usd', 'stock_qty'],
        ));
        $this->actingAs($user)->post('/products/import');

        $product = Product::sole();
        $this->assertSame('0.0000', $product->cost_usd);
        $this->assertSame('0.0000', $product->stock_qty);
        $this->assertSame('pcs', $product->unit);
        $this->assertTrue($product->is_active);
    }

    public function test_one_audit_entry_per_batch_not_per_row(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv([
            ['A', 'Beverages', '1.00', '', 'A-1'],
            ['B', 'Beverages', '2.00', '', 'B-1'],
            ['C', 'Beverages', '3.00', '', 'C-1'],
        ]));
        $this->actingAs($user)->post('/products/import');

        $entry = AuditLog::where('action', 'products_bulk_import')->sole();
        $this->assertSame(3, $entry->new_values['created']);
        $this->assertSame($user->id, $entry->user_id);
    }

    // === Duplicate barcodes: reported, skipped, rest still imports ===

    public function test_barcode_already_in_the_catalogue_is_skipped_not_inserted(): void
    {
        $user = $this->importer();
        Product::factory()->create(['barcode' => '1000000000009', 'name' => 'Existing Water']);

        $this->preview($user, $this->csv([
            ['Water 500ml', 'Beverages', '0.50', '1000000000009', 'W-500'],
            ['Juice 1L', 'Beverages', '2.25', '1000000000016', 'J-1L'],
        ]))->assertOk()->assertSee('Existing Water');

        $this->actingAs($user)->post('/products/import')
            ->assertSessionHas('success', 'Imported 1 product(s).');

        $this->assertDatabaseMissing('products', ['name' => 'Water 500ml']);
        $this->assertDatabaseHas('products', ['name' => 'Juice 1L']);
    }

    public function test_barcode_of_a_soft_deleted_product_still_counts_as_duplicate(): void
    {
        $user = $this->importer();
        // The unique index keeps the row, so a "free" barcode here would blow up
        // at INSERT. This is why findByBarcode() uses withTrashed().
        Product::factory()->create(['barcode' => '1000000000009'])->delete();

        $this->preview($user, $this->csv([['Water', 'Beverages', '0.50', '1000000000009', 'W-1']]));
        $this->actingAs($user)->post('/products/import')
            ->assertSessionHas('success', 'Imported 0 product(s).');

        $this->assertDatabaseMissing('products', ['name' => 'Water']);
    }

    public function test_a_barcode_repeated_inside_the_file_is_a_duplicate(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv([
            ['First', 'Beverages', '1.00', '1000000000009', 'F-1'],
            ['Second', 'Beverages', '2.00', '1000000000009', 'S-1'],
        ]));

        $this->actingAs($user)->post('/products/import')
            ->assertSessionHas('success', 'Imported 1 product(s).');

        $this->assertDatabaseHas('products', ['name' => 'First']);
        $this->assertDatabaseMissing('products', ['name' => 'Second']);
    }

    // === Errors: reported per row, and nothing commits ===

    public function test_malformed_rows_are_reported_with_row_number_and_reason(): void
    {
        $this->preview($this->importer(), $this->csv([
            ['Good', 'Beverages', '1.00', '', 'G-1'],
            ['', 'Beverages', '2.00', '', 'B-1'],
            ['Bad Category', 'Nonexistent', '3.00', '', 'B-2'],
            ['Bad Price', 'Beverages', 'not-a-number', '', 'B-3'],
        ]))
            ->assertOk()
            ->assertSee('Row 3')
            ->assertSee('Row 4')
            ->assertSee('Row 5')
            ->assertSee('Unknown category');
    }

    public function test_a_file_with_any_error_commits_nothing(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv([
            ['Good', 'Beverages', '1.00', '', 'G-1'],
            ['Bad Price', 'Beverages', 'not-a-number', '', 'B-1'],
        ]));

        $this->actingAs($user)->post('/products/import')->assertSessionHasErrors('file');

        // The valid row must not have slipped through on its own.
        $this->assertSame(0, Product::count());
        $this->assertSame(0, AuditLog::where('action', 'products_bulk_import')->count());
    }

    public function test_a_file_missing_a_required_column_is_refused_outright(): void
    {
        $this->preview($this->importer(), $this->csv(
            [['Water', '1.00']],
            ['name', 'price_usd'],
        ))->assertSessionHasErrors('file');

        $this->assertSame(0, Product::count());
    }

    public function test_trailing_blank_rows_are_ignored_not_errors(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv([
            ['Water', 'Beverages', '1.00', '', 'W-1'],
            ['', '', '', '', ''],
        ]));

        $this->actingAs($user)->post('/products/import')
            ->assertSessionHas('success', 'Imported 1 product(s).');
    }

    // === Confirm is its own trust boundary ===

    public function test_confirming_without_a_previewed_upload_is_refused(): void
    {
        $this->actingAs($this->importer())->post('/products/import')
            ->assertRedirect(route('products.import.show'))
            ->assertSessionHasErrors('file');
    }

    public function test_the_same_upload_cannot_be_committed_twice(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv([['Water', 'Beverages', '1.00', '', 'W-1']]));
        $this->actingAs($user)->post('/products/import');
        $this->actingAs($user)->post('/products/import')->assertSessionHasErrors('file');

        $this->assertSame(1, Product::count());
    }

    public function test_a_barcode_taken_between_preview_and_confirm_is_caught(): void
    {
        $user = $this->importer();

        $this->preview($user, $this->csv([['Water', 'Beverages', '1.00', '1000000000009', 'W-1']]));

        // Someone adds it at the till while the preview sits on screen.
        Product::factory()->create(['barcode' => '1000000000009']);

        $this->actingAs($user)->post('/products/import')
            ->assertSessionHas('success', 'Imported 0 product(s).');

        $this->assertDatabaseMissing('products', ['name' => 'Water']);
    }
}
