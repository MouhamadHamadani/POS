<?php

namespace Tests\Feature\BugHunt;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-001: the "Active" checkbox on a category row can be ticked but never un-ticked.
 *
 * An unchecked checkbox is simply absent from the request, and
 * CategoryController::update() reads it with `boolean('is_active', true)`, so
 * "absent" means "keep it active". The sell screen only lists active categories,
 * so a category that was meant to be hidden stays on the till.
 */
class Bug001CategoryCannotBeDeactivatedTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_unticking_active_on_the_category_row_and_saving_deactivates_it(): void
    {
        $category = Category::create(['name' => 'Seasonal', 'sort_order' => 1, 'is_active' => true]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('categories.index'))->assertOk()->getContent();

        $this->submitRenderedForm($html, route('categories.update', $category), uncheck: ['is_active'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($category->fresh()->is_active, 'The category stayed active after the box was unticked.');
    }
}
