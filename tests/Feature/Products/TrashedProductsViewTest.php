<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The deleted-products list is the vendor's, not the client's: a client admin
 * must not reach it, see a link to it, or find a deleted product's codes leaking
 * out of any other endpoint.
 */
class TrashedProductsViewTest extends TestCase
{
    use RefreshDatabase;

    private function deletedProduct(string $barcode = '5901234123457', string $sku = 'CC-1L'): Product
    {
        $product = Product::factory()->create([
            'name' => 'Ghost Cola', 'barcode' => $barcode, 'sku' => $sku,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->delete("/products/{$product->id}")->assertRedirect();

        // Drop the delete's own "Deleted 'Ghost Cola'" flash, or the next request
        // renders it and every assertDontSee below matches the banner, not a leak.
        $this->flushSession();

        return $product;
    }

    public function test_destroy_records_the_old_codes_on_the_product_row(): void
    {
        $product = $this->deletedProduct();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'barcode' => null, 'sku' => null,
            'old_barcode' => '5901234123457', 'old_sku' => 'CC-1L',
        ]);
    }

    public function test_super_admin_sees_the_deleted_product_with_its_old_codes(): void
    {
        $this->deletedProduct();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/products/trashed')
            ->assertOk()
            ->assertSee('Ghost Cola')
            ->assertSee('5901234123457')
            ->assertSee('CC-1L')
            ->assertSee('Deleted Products');
    }

    public function test_super_admin_only_sees_a_link_to_it(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/products')->assertOk()->assertSee(route('products.trashed'));

        $this->actingAs(User::factory()->admin()->create())
            ->get('/products')->assertOk()->assertDontSee(route('products.trashed'));
    }

    public static function nonSuperAdminRoles(): array
    {
        return [
            'admin' => [User::ROLE_ADMIN],
            'manager' => [User::ROLE_MANAGER],
            'stock' => [User::ROLE_STOCK],
            'cashier' => [User::ROLE_CASHIER],
        ];
    }

    #[DataProvider('nonSuperAdminRoles')]
    public function test_every_other_role_is_forbidden_even_by_direct_url(string $role): void
    {
        $this->deletedProduct();

        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/products/trashed')->assertForbidden();
    }

    #[DataProvider('nonSuperAdminRoles')]
    public function test_no_other_role_sees_a_deleted_product_anywhere(string $role): void
    {
        $this->deletedProduct();
        $user = User::factory()->create(['role' => $role]);

        // The product list, a search that would match it, and the barcode check
        // an admin/manager/stock account can legitimately call.
        if (in_array($role, [User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_STOCK], true)) {
            $this->actingAs($user)->get('/products')
                ->assertOk()->assertDontSee('Ghost Cola');

            $this->actingAs($user)->get('/products?search=Ghost')
                ->assertOk()->assertDontSee('Ghost Cola');

            $this->actingAs($user)->getJson('/products/check-barcode?barcode=5901234123457')
                ->assertOk()->assertJson(['exists' => false])
                ->assertJsonMissing(['product_name' => 'Ghost Cola']);
        }

        $this->actingAs($user)->get('/products/trashed')->assertForbidden();
    }

    public function test_old_barcode_never_blocks_a_new_product_taking_that_code(): void
    {
        $this->deletedProduct();
        $cat = Category::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->post('/products', [
            'name' => 'Fresh Cola',
            'category_id' => $cat->id,
            'price_usd' => 1.50,
            'barcode' => '5901234123457',
            'sku' => 'CC-1L',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Fresh Cola', Product::where('barcode', '5901234123457')->value('name'));
    }

    public function test_backfill_recovers_the_codes_from_an_existing_audit_log_entry(): void
    {
        $product = $this->deletedProduct();

        // Rewind to how a row looked before this migration: codes freed by the
        // earlier fix, recoverable only from the delete's audit-log entry.
        DB::table('products')->where('id', $product->id)
            ->update(['old_barcode' => null, 'old_sku' => null]);

        $this->runBackfill();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'old_barcode' => '5901234123457', 'old_sku' => 'CC-1L',
        ]);
    }

    public function test_backfill_leaves_an_unrecoverable_product_null_without_crashing(): void
    {
        $product = $this->deletedProduct();

        // Deleted before any of this shipped: no delete entry to read, and an
        // unreadable entry for a second product to prove neither aborts the run.
        AuditLog::where('model_type', Product::class)->where('model_id', $product->id)->delete();
        DB::table('products')->where('id', $product->id)
            ->update(['old_barcode' => null, 'old_sku' => null]);

        $garbled = Product::factory()->create(['barcode' => '4001234567890', 'sku' => 'G-1']);
        $this->actingAs(User::factory()->superAdmin()->create())->delete("/products/{$garbled->id}");
        DB::table('products')->where('id', $garbled->id)
            ->update(['old_barcode' => null, 'old_sku' => null]);
        DB::table('audit_logs')->where('model_id', $garbled->id)
            ->where('action', 'delete')->update(['old_values' => 'not json at all']);

        $this->runBackfill();

        $this->assertDatabaseHas('products', [
            'id' => $product->id, 'old_barcode' => null, 'old_sku' => null,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $garbled->id, 'old_barcode' => null, 'old_sku' => null,
        ]);

        // Still listable — a null old_barcode renders, it does not blow the view up.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/products/trashed')->assertOk()->assertSee('Not recorded');
    }

    private function runBackfill(): void
    {
        (require base_path('database/migrations/2026_09_24_000002_add_old_codes_to_products.php'))->up();
    }
}
