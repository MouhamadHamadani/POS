<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-020: creating, renaming and deleting a category are audited with the values before and after. */
class CategoryAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_update_and_delete_each_leave_an_entry(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('categories.store'), ['name' => 'Snacks', 'sort_order' => 2])->assertSessionHasNoErrors();
        $category = Category::where('name', 'Snacks')->firstOrFail();
        $create = AuditLog::where('action', 'create')->where('model_type', Category::class)->sole();
        $this->assertSame($category->id, $create->model_id);
        $this->assertNull($create->old_values);
        $this->assertSame('Snacks', $create->new_values['name']);

        $this->actingAs($admin)->put(route('categories.update', $category), ['name' => 'Treats', 'sort_order' => 2, 'is_active' => '1'])->assertSessionHasNoErrors();
        $update = AuditLog::where('action', 'update')->where('model_type', Category::class)->sole();
        $this->assertSame('Snacks', $update->old_values['name']);
        $this->assertSame('Treats', $update->new_values['name']);

        $this->actingAs($admin)->delete(route('categories.destroy', $category))->assertSessionHasNoErrors();
        $delete = AuditLog::where('action', 'delete')->where('model_type', Category::class)->sole();
        $this->assertSame($category->id, $delete->model_id);
        $this->assertSame('Treats', $delete->old_values['name']);
        $this->assertNull($delete->new_values);
        $this->assertSame($admin->id, $delete->user_id);
    }

    public function test_a_refused_delete_leaves_no_entry(): void
    {
        $category = Category::create(['name' => 'Drinks', 'sort_order' => 1, 'is_active' => true]);
        Product::factory()->create(['category_id' => $category->id]);

        $this->actingAs(User::factory()->admin()->create())->delete(route('categories.destroy', $category))->assertSessionHasErrors('delete');

        $this->assertSame(0, AuditLog::count());
        $this->assertNotNull($category->fresh());
    }
}
