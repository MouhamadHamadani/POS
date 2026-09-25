<?php

namespace Tests\Feature\Pos;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestPrintReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function post80(User $user, array $settings = [])
    {
        return $this->actingAs($user)->post('/settings/receipt/test-print', [
            'group' => 'receipt',
            'settings' => $settings + ['receipt_width' => 80, 'receipt_header' => '', 'receipt_footer' => ''],
        ]);
    }

    public function test_renders_unsaved_values_and_writes_nothing(): void
    {
        Setting::set('receipt_header', 'Saved header', 'receipt');
        Setting::set('receipt_counter', 7, 'numbering', 'int');
        $admin = User::factory()->admin()->create();
        $settingsBefore = DB::table('settings')->get()->toArray();
        $auditsBefore = DB::table('audit_logs')->count();

        $this->post80($admin, ['receipt_width' => 58, 'receipt_header' => 'Unsaved header', 'receipt_footer' => 'Unsaved footer'])
            ->assertOk()
            ->assertSee('size: 58mm', false)
            ->assertSee('Unsaved header')
            ->assertDontSee('Saved header')
            ->assertSee('Unsaved footer')
            ->assertSee('TEST PRINT')
            ->assertSee('طباعة تجريبية')
            ->assertSee('TEST-')
            ->assertSee('addEventListener', false);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertSame($auditsBefore, DB::table('audit_logs')->count());
        $this->assertEquals($settingsBefore, DB::table('settings')->get()->toArray());
    }

    public function test_sample_totals_add_up(): void
    {
        // 2×4.50 + (12.00−2.00) + 0.75×6.00 = 23.50 net; VAT 11% on the first two = 2.09.
        $this->post80(User::factory()->admin()->create())
            ->assertSee('$25.50') // subtotal (gross)
            ->assertSee('-$2.00') // discount
            ->assertSee('$2.09')  // VAT
            ->assertSee('$25.59') // total
            ->assertSee('LBP equiv.')
            ->assertSee('Change USD')
            ->assertSee('Change LBP');
    }

    public function test_only_admins_can_test_print(): void
    {
        $this->post80(User::factory()->superAdmin()->create())->assertOk();

        foreach ([User::factory()->manager()->create(), User::factory()->create(['role' => User::ROLE_CASHIER]), User::factory()->stock()->create()] as $user) {
            $this->post80($user)->assertForbidden();
        }
    }
}
