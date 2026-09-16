<?php

namespace Tests\Feature\Setup;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedSomething(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        User::create([
            'name' => 'Owner',
            'username' => 'owner',
            'password' => 'a-real-password',
            'role' => User::ROLE_SUPER_ADMIN,
            'language' => 'en',
            'is_active' => true,
            'max_discount_pct' => 100,
        ]);

        $category = Category::create(['name' => 'Beverages', 'name_ar' => 'مشروبات']);
        Product::create([
            'name' => 'Coca Cola 330ml',
            'name_ar' => 'كوكا كولا',
            'sku' => 'COKE-330',
            'category_id' => $category->id,
            'price_usd' => 1.50,
            'is_active' => true,
        ]);
    }

    public function test_it_clears_catalogue_and_users_but_keeps_configuration(): void
    {
        $this->seedSomething();
        $settingsBefore = Setting::count();

        $this->artisan('pos:reset-data --force')->assertSuccessful();

        $this->assertSame(0, Product::count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, User::count());

        $this->assertSame($settingsBefore, Setting::count(), 'Settings must survive a reset.');
        $this->assertGreaterThan(0, DB::table('taxes')->count(), 'Tax config must survive a reset.');
        $this->assertGreaterThan(0, DB::table('currencies')->count(), 'Currency config must survive a reset.');
    }

    public function test_keep_users_leaves_accounts_alone(): void
    {
        $this->seedSomething();

        $this->artisan('pos:reset-data --force --keep-users')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(0, Product::count());
    }

    public function test_declining_the_prompt_deletes_nothing(): void
    {
        $this->seedSomething();

        $this->artisan('pos:reset-data')
            ->expectsConfirmation('Permanently delete these 3 rows?', 'no')
            ->assertFailed();

        $this->assertSame(1, User::count());
        $this->assertSame(1, Product::count());
        $this->assertSame(1, Category::count());
    }

    public function test_it_is_a_no_op_on_an_already_clean_database(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->artisan('pos:reset-data')
            ->expectsOutputToContain('already in a clean state')
            ->assertSuccessful();
    }

    public function test_a_reset_machine_lands_back_on_the_setup_screen(): void
    {
        $this->seedSomething();

        $this->artisan('pos:reset-data --force')->assertSuccessful();

        $this->get('/')->assertRedirect(route('setup.show'));
        $this->get('/setup')->assertOk();
    }
}
