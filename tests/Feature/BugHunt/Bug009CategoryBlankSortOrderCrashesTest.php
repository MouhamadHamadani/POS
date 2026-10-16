<?php

namespace Tests\Feature\BugHunt;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-009: emptying "Sort" on a category row (or on the add-category form) ends in a server error.
 *
 * The number input has no `required`, the rule is `nullable|integer`, and a blank input
 * reaches the controller as null. `categories.sort_order` is NOT NULL DEFAULT 0, so the
 * insert/update throws. Expense categories handle this (`(int) ($data['sort_order'] ?? 0)`).
 */
class Bug009CategoryBlankSortOrderCrashesTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_saving_a_category_row_with_a_blank_sort_order_does_not_crash(): void
    {
        $category = Category::create(['name' => 'Drinks', 'sort_order' => 3, 'is_active' => true]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('categories.index'))->assertOk()->getContent();

        $response = $this->submitRenderedForm($html, route('categories.update', $category), set: ['sort_order' => '']);

        $this->assertLessThan(500, $response->getStatusCode(), 'A blank sort order produced a server error: '.($response->exception?->getMessage() ?? ''));
    }

    public function test_adding_a_category_with_a_blank_sort_order_does_not_crash(): void
    {
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('categories.index'))->assertOk()->getContent();

        $response = $this->submitRenderedForm($html, route('categories.store'), set: ['name' => 'Snacks', 'sort_order' => '']);

        $this->assertLessThan(500, $response->getStatusCode(), 'A blank sort order produced a server error: '.($response->exception?->getMessage() ?? ''));
    }
}
