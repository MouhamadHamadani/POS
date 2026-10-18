<?php

namespace Tests\Feature\BugHunt;

use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-038: when a return is submitted for a sale that can no longer take one (it was voided or fully refunded after
 * the form was opened, e.g. by a second till, or the submit button was pressed twice), ReturnController::store()
 * redirects to the returns LIST with the error `sale`. returns/index.blade.php renders no errors, so the user lands on
 * a list with no new return and no explanation. (create()/show()/search all render their errors.)
 */
class Bug038ReturnRefusedForNonReturnableSaleShowsNoMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_a_return_for_a_fully_refunded_sale_explains_why_nothing_was_created(): void
    {
        $manager = User::factory()->manager()->create();
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $sale = Sale::create([
            'receipt_number' => 'R-0800', 'user_id' => $manager->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 10, 'total_usd' => 10, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_REFUNDED,
        ]);

        $page = $this->actingAs($manager)->followingRedirects()->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => 1, 'qty_returned' => 1, 'restock' => 1]],
            'reason' => 'other', 'refund_method' => 'cash_usd',
        ]);

        $page->assertOk();
        $html = $page->getContent();
        $this->assertTrue(str_contains($html, e(__('returns.new_return'))), 'Precondition: the user lands on the returns page.');
        $this->assertTrue(
            str_contains($html, e(__('messages.sale_cannot_return'))),
            'The return was refused but the page the user lands on does not say why.',
        );
    }
}
