<?php

namespace Tests\Feature\BugHunt;

use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\BugHunt\Concerns\SubmitsRenderedForms;
use Tests\TestCase;

/**
 * BUG-004: unticking "Active" on a tax-rate row in Settings > Tax does not deactivate the rate.
 *
 * SettingController::updateTax() reads the checkbox with `boolean('is_active', true)`, so
 * the missing field an unticked box produces means "stay active". The other two checkboxes
 * in the same row (inclusive, default) are read with no default and work.
 */
class Bug004TaxRateCannotBeDeactivatedTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsRenderedForms;

    public function test_unticking_active_on_the_tax_row_and_saving_deactivates_the_rate(): void
    {
        $tax = Tax::factory()->create(['name' => 'Old VAT', 'rate' => 0.11, 'is_active' => true]);
        $admin = User::factory()->admin()->create();

        $html = $this->actingAs($admin)->get(route('settings.index', ['tab' => 'tax']))->assertOk()->getContent();

        $this->submitRenderedForm($html, route('settings.tax.update', $tax), uncheck: ['is_active'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($tax->fresh()->is_active, 'The tax rate stayed active after the box was unticked.');
    }
}
