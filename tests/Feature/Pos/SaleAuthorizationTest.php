<?php

namespace Tests\Feature\Pos;

use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function saleFor(User $cashier): Sale
    {
        $shift = Shift::create([
            'user_id' => $cashier->id,
            'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => Shift::STATUS_OPEN,
        ]);
        return Sale::create([
            'receipt_number' => 'AUTH-TEST',
            'user_id' => $cashier->id,
            'shift_id' => $shift->id,
            'subtotal_usd' => 1, 'tax_amount_usd' => 0, 'total_usd' => 1,
            'exchange_rate' => 100000,
            'payment_method' => Sale::METHOD_CASH_USD,
            'status' => Sale::STATUS_COMPLETED,
        ]);
    }

    public function test_cashier_can_only_show_their_own_sale(): void
    {
        $alice = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $bob = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $aliceShift = Shift::create([
            'user_id' => $alice->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => Shift::STATUS_OPEN,
        ]);
        $bobSale = $this->saleFor($bob);

        $this->actingAs($alice)->getJson("/pos/api/sales/{$bobSale->id}")
            ->assertForbidden();
    }

    public function test_manager_can_show_any_sale(): void
    {
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $manager = User::factory()->manager()->create();
        Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => Shift::STATUS_OPEN,
        ]);
        $sale = $this->saleFor($cashier);

        $this->actingAs($manager)->getJson("/pos/api/sales/{$sale->id}")
            ->assertOk()->assertJsonPath('receipt_number', 'AUTH-TEST');
    }
}
