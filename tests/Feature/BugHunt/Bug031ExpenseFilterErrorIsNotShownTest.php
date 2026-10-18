<?php

namespace Tests\Feature\BugHunt;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-031: an invalid date range on the Expenses filter is rejected without telling the user.
 *
 * ExpenseController::filters() validates `to` as `after_or_equal:from`. On failure Laravel redirects
 * back with the error in the session, but expenses/index.blade.php renders only the `expense` and
 * `demo` error keys, so picking an end date before the start date makes "Apply" appear to do nothing:
 * the page comes back with the previous results and no message. The stock-movements and
 * inventory-by-date filters do display their errors.
 */
class Bug031ExpenseFilterErrorIsNotShownTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_end_date_before_the_start_date_shows_a_validation_message(): void
    {
        $manager = User::factory()->manager()->create();

        $page = $this->actingAs($manager)
            ->from(route('expenses.index'))
            ->followingRedirects()
            ->get(route('expenses.index', ['from' => '2026-10-10', 'to' => '2026-10-01']));

        $page->assertOk();
        $html = $page->getContent();
        $this->assertTrue(
            str_contains($html, 'after or equal') || str_contains($html, 'bg-red-50'),
            'The filter was rejected (to < from) but the page shows no error message.',
        );
    }
}
