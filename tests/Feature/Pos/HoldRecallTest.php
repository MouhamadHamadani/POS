<?php

namespace Tests\Feature\Pos;

use App\Models\HeldSale;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldRecallTest extends TestCase
{
    use RefreshDatabase;

    private function activeShiftAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        \App\Models\Shift::create([
            'user_id' => $admin->id,
            'opened_at' => now(),
            'opening_cash_usd' => 50,
            'opening_cash_lbp' => 0,
            'status' => \App\Models\Shift::STATUS_OPEN,
        ]);
        return $admin;
    }

    public function test_cashier_can_hold_a_cart(): void
    {
        $user = $this->activeShiftAdmin();
        $product = Product::factory()->create();

        $response = $this->actingAs($user)->postJson('/pos/api/holds', [
            'label' => 'Table 5',
            'cart' => [
                ['product_id' => $product->id, 'qty' => 2, 'unit_price' => 5.00],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('held_sales', [
            'user_id' => $user->id,
            'label' => 'Table 5',
        ]);
    }

    public function test_hold_does_not_deduct_stock(): void
    {
        $user = $this->activeShiftAdmin();
        $product = Product::factory()->create(['stock_qty' => 10]);

        $this->actingAs($user)->postJson('/pos/api/holds', [
            'cart' => [['product_id' => $product->id, 'qty' => 3, 'unit_price' => 1]],
        ])->assertCreated();

        $this->assertSame('10.0000', (string) $product->fresh()->stock_qty);
    }

    public function test_holds_are_scoped_per_cashier(): void
    {
        $alice = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $bob = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $product = Product::factory()->create();

        HeldSale::create([
            'user_id' => $alice->id,
            'label' => 'A',
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1]],
        ]);
        HeldSale::create([
            'user_id' => $bob->id,
            'label' => 'B',
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1]],
        ]);

        \App\Models\Shift::create([
            'user_id' => $alice->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => \App\Models\Shift::STATUS_OPEN,
        ]);

        $response = $this->actingAs($alice)->getJson('/pos/api/holds');
        $response->assertOk();
        $body = $response->json();
        $this->assertCount(1, $body);
        $this->assertSame('A', $body[0]['label']);
    }

    public function test_manager_can_see_all_holds(): void
    {
        $manager = User::factory()->manager()->create();
        $cashier = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $product = Product::factory()->create();

        HeldSale::create([
            'user_id' => $cashier->id,
            'label' => 'Cashier hold',
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1]],
        ]);

        \App\Models\Shift::create([
            'user_id' => $manager->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => \App\Models\Shift::STATUS_OPEN,
        ]);

        $this->actingAs($manager)->getJson('/pos/api/holds')->assertOk()->assertJsonCount(1);
    }

    public function test_recall_returns_cart_and_removes_hold(): void
    {
        $user = $this->activeShiftAdmin();
        $product = Product::factory()->create();
        $hold = HeldSale::create([
            'user_id' => $user->id,
            'cart' => [
                ['product_id' => $product->id, 'qty' => 4, 'unit_price' => 2.50],
            ],
        ]);

        $response = $this->actingAs($user)->postJson("/pos/api/holds/{$hold->id}/recall");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cart.0.product_id', $product->id);

        $this->assertDatabaseMissing('held_sales', ['id' => $hold->id]);
    }

    public function test_cashier_cannot_recall_someone_elses_hold(): void
    {
        $alice = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $bob = User::factory()->create(['role' => User::ROLE_CASHIER]);
        $product = Product::factory()->create();

        \App\Models\Shift::create([
            'user_id' => $alice->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => \App\Models\Shift::STATUS_OPEN,
        ]);

        $hold = HeldSale::create([
            'user_id' => $bob->id,
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1]],
        ]);

        $this->actingAs($alice)->postJson("/pos/api/holds/{$hold->id}/recall")
            ->assertForbidden();

        $this->assertDatabaseHas('held_sales', ['id' => $hold->id]);
    }

    public function test_discard_removes_the_hold(): void
    {
        $user = $this->activeShiftAdmin();
        $product = Product::factory()->create();
        $hold = HeldSale::create([
            'user_id' => $user->id,
            'cart' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 1]],
        ]);

        $this->actingAs($user)->deleteJson("/pos/api/holds/{$hold->id}")->assertOk();
        $this->assertDatabaseMissing('held_sales', ['id' => $hold->id]);
    }

    public function test_multiple_holds_can_coexist(): void
    {
        $user = $this->activeShiftAdmin();
        $product = Product::factory()->create();

        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($user)->postJson('/pos/api/holds', [
                'label' => "Hold {$i}",
                'cart' => [['product_id' => $product->id, 'qty' => $i, 'unit_price' => 5]],
            ])->assertCreated();
        }

        $list = $this->actingAs($user)->getJson('/pos/api/holds')->assertOk()->json();
        $this->assertCount(3, $list);
    }

    public function test_hold_validation_rejects_empty_cart(): void
    {
        $user = $this->activeShiftAdmin();
        $this->actingAs($user)->postJson('/pos/api/holds', ['cart' => []])
            ->assertStatus(422)->assertJsonValidationErrors('cart');
    }
}
