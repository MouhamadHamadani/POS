<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a category, and — the part that actually bit — what the screen does
 * when you can't.
 *
 * The category list used to render a live-looking red "Delete" with a bare
 * `disabled` attribute for any category holding products. It looked clickable,
 * swallowed the click, showed nothing, and never reached the confirm dialog, so
 * the whole feature read as broken.
 */
class CategoryDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_a_category_holding_products_offers_no_delete_control_and_says_why(): void
    {
        $category = Category::create(['name' => 'Beverages', 'sort_order' => 1, 'is_active' => true]);
        Product::factory()->count(2)->create(['category_id' => $category->id]);

        $response = $this->actingAs($this->admin())->get('/categories')->assertOk();

        // The row's edit form shares the destroy URL (PUT vs DELETE on
        // /categories/{id}), so the spoofed method is what says a delete exists.
        $response->assertDontSee('value="DELETE"', false);
        $response->assertSee('2 products still assigned', false);
    }

    public function test_an_empty_category_offers_a_delete_guarded_by_a_confirmation(): void
    {
        $category = Category::create(['name' => 'Empty Aisle', 'sort_order' => 1, 'is_active' => true]);

        $response = $this->actingAs($this->admin())->get('/categories')->assertOk();

        $response->assertSee('value="DELETE"', false);
        $response->assertSee('action="'.route('categories.destroy', $category).'"', false);
        $response->assertSee('confirm(', false);
        $response->assertSee('This cannot be undone', false);
    }

    public function test_a_name_with_an_apostrophe_does_not_break_the_confirmation(): void
    {
        Category::create(['name' => "Bill's Aisle", 'sort_order' => 1, 'is_active' => true]);

        $response = $this->actingAs($this->admin())->get('/categories')->assertOk();

        // Interpolated raw, the apostrophe would close the JS string early, the
        // handler would throw, and the form would submit with no prompt at all.
        $response->assertDontSee("confirm('Delete category Bill's", false);
        $response->assertSee('Bill\\u0027s', false);
    }

    public function test_deleting_an_empty_category_works(): void
    {
        $category = Category::create(['name' => 'Empty Aisle', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->delete(route('categories.destroy', $category))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_the_server_refuses_to_delete_a_category_holding_products(): void
    {
        $category = Category::create(['name' => 'Beverages', 'sort_order' => 1, 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs($this->admin())
            ->delete(route('categories.destroy', $category))
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }
}
