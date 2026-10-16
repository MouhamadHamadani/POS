<?php

namespace Tests\Feature\BugHunt;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-020: creating, renaming or deleting a product category leaves no audit-log entry.
 *
 * CategoryController::store(), update() and destroy() never call AuditLog::record(), against
 * the project rule that every mutating action is audited (Products, Customers, Suppliers,
 * Expenses and expense categories all are).
 */
class Bug020CategoryChangesAreNotAuditedTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_category_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('categories.store'), ['name' => 'Snacks', 'sort_order' => 1])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, AuditLog::where('user_id', $admin->id)->count(), 'Creating a category left no audit-log entry.');
    }

    public function test_renaming_a_category_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Drinks', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($admin)->put(route('categories.update', $category), [
            'name' => 'Beverages', 'sort_order' => 1, 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, AuditLog::where('user_id', $admin->id)->count(), 'Renaming a category left no audit-log entry.');
    }

    public function test_deleting_a_category_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Empty', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($admin)->delete(route('categories.destroy', $category))->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, AuditLog::where('user_id', $admin->id)->count(), 'Deleting a category left no audit-log entry.');
    }
}
