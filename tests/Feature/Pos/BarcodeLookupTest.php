<?php

namespace Tests\Feature\Pos;

use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarcodeLookupTest extends TestCase
{
    use RefreshDatabase;

    private function userWithShift(): User
    {
        $user = User::factory()->admin()->create();
        Shift::create([
            'user_id' => $user->id, 'opened_at' => now(),
            'opening_cash_usd' => 0, 'opening_cash_lbp' => 0,
            'status' => Shift::STATUS_OPEN,
        ]);
        return $user;
    }

    public function test_known_barcode_returns_product(): void
    {
        $user = $this->userWithShift();
        $product = Product::factory()->create(['barcode' => '5901234123457']);

        $this->actingAs($user)->getJson('/pos/api/barcode?code=5901234123457')
            ->assertOk()
            ->assertJsonPath('id', $product->id);
    }

    public function test_unknown_barcode_returns_404(): void
    {
        $user = $this->userWithShift();
        $this->actingAs($user)->getJson('/pos/api/barcode?code=NONEXISTENT')
            ->assertStatus(404);
    }

    public function test_empty_barcode_returns_422(): void
    {
        $user = $this->userWithShift();
        $this->actingAs($user)->getJson('/pos/api/barcode?code=')
            ->assertStatus(422);
    }

    public function test_inactive_product_is_not_returned_via_barcode(): void
    {
        $user = $this->userWithShift();
        Product::factory()->create([
            'barcode' => 'INACTIVE-CODE',
            'is_active' => false,
        ]);

        $this->actingAs($user)->getJson('/pos/api/barcode?code=INACTIVE-CODE')
            ->assertStatus(404);
    }

    public function test_soft_deleted_product_is_not_returned(): void
    {
        $user = $this->userWithShift();
        $product = Product::factory()->create(['barcode' => 'SOFT-DEL']);
        $product->delete();

        $this->actingAs($user)->getJson('/pos/api/barcode?code=SOFT-DEL')
            ->assertStatus(404);
    }
}
