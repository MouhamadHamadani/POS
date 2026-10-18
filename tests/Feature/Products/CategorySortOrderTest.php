<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-009: a blank category sort order means 0 (the column default); an omitted one keeps the stored value. */
class CategorySortOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blank_sort_order_is_saved_as_zero_on_create_and_update(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('categories.store'), ['name' => 'Snacks', 'sort_order' => ''])->assertSessionHasNoErrors();
        $this->assertSame(0, Category::where('name', 'Snacks')->firstOrFail()->sort_order);

        $category = Category::create(['name' => 'Drinks', 'sort_order' => 3, 'is_active' => true]);
        $this->actingAs($admin)->put(route('categories.update', $category), ['name' => 'Drinks', 'sort_order' => '', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(0, $category->fresh()->sort_order);
    }

    public function test_an_omitted_sort_order_keeps_the_stored_value(): void
    {
        $category = Category::create(['name' => 'Drinks', 'sort_order' => 3, 'is_active' => true]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('categories.update', $category), ['name' => 'Beverages', 'is_active' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(3, $category->fresh()->sort_order);
        $this->assertSame('Beverages', $category->fresh()->name);
    }
}
