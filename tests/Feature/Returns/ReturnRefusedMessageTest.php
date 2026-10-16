<?php

namespace Tests\Feature\Returns;

use App\Models\ReturnTransaction;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-038: a return refused because the sale can no longer take one says so on the returns list, in the user's language. */
class ReturnRefusedMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_refusal_is_shown_in_arabic_for_an_arabic_user_and_nothing_is_created(): void
    {
        $manager = User::factory()->manager()->create(['language' => 'ar']);
        $shift = Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0, 'status' => Shift::STATUS_OPEN,
        ]);
        $sale = Sale::create([
            'receipt_number' => 'R-0810', 'user_id' => $manager->id, 'shift_id' => $shift->id,
            'subtotal_usd' => 10, 'total_usd' => 10, 'exchange_rate' => 90000,
            'payment_method' => 'cash_usd', 'status' => Sale::STATUS_VOIDED,
        ]);

        $this->actingAs($manager)->followingRedirects()->post(route('returns.store', $sale), [
            'items' => [['sale_item_id' => 1, 'qty_returned' => 1, 'restock' => 1]],
            'reason' => 'other', 'refund_method' => 'cash_usd',
        ])->assertOk()->assertSee(__('messages.sale_cannot_return', [], 'ar'));

        $this->assertSame(0, ReturnTransaction::count());
    }

    public function test_a_plain_visit_to_the_list_shows_no_error_box(): void
    {
        $this->actingAs(User::factory()->manager()->create())->get(route('returns.index'))
            ->assertOk()->assertDontSee('bg-red-50', false);
    }
}
