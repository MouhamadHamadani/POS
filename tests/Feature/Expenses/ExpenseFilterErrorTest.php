<?php

namespace Tests\Feature\Expenses;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-031: a rejected date range on the Expenses filter says why and keeps the dates the user entered. */
class ExpenseFilterErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_message_is_shown_in_the_users_language_and_the_dates_stay_in_the_inputs(): void
    {
        $manager = User::factory()->manager()->create(['language' => 'ar']);

        $html = $this->actingAs($manager)->from(route('expenses.index'))->followingRedirects()
            ->get(route('expenses.index', ['from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertOk()->getContent();

        $this->assertStringContainsString(trans('validation.after_or_equal', ['attribute' => trans('reports.to', [], 'ar'), 'date' => trans('reports.from', [], 'ar')], 'ar'), $html);
        $this->assertMatchesRegularExpression('/name="from"[^>]*value="2026-10-10"/', $html);
        $this->assertMatchesRegularExpression('/name="to"[^>]*value="2026-10-01"/', $html);
    }
}
