<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-044: the default tax cannot be deactivated while it is still the default. */
class TaxDefaultDeactivateTest extends TestCase
{
    use RefreshDatabase;

    private function save(Tax $tax, array $over, string $language = 'en')
    {
        return $this->actingAs(User::factory()->admin()->create(['language' => $language]))
            ->put(route('settings.tax.update', $tax), ['name' => 'VAT', 'rate' => 0.11] + $over);
    }

    public function test_deactivating_the_default_tax_is_refused_in_both_languages_and_changes_nothing(): void
    {
        $vat = Tax::factory()->default()->create(['rate' => 0.11]);

        $this->save($vat, ['is_default' => '1', 'is_active' => '0'])
            ->assertSessionHasErrors(['is_active' => __('messages.tax_default_inactive')]);
        $this->save($vat, ['is_default' => '1', 'is_active' => '0'], 'ar')
            ->assertSessionHasErrors(['is_active' => trans('messages.tax_default_inactive', [], 'ar')]);

        $this->assertTrue($vat->fresh()->is_active);
        $this->assertTrue($vat->fresh()->is_default);
        $this->assertSame(0, AuditLog::where('model_type', Tax::class)->count());
    }

    public function test_a_non_default_tax_can_be_deactivated_and_the_default_can_be_unflagged_while_deactivating(): void
    {
        $vat = Tax::factory()->default()->create(['rate' => 0.11]);
        $other = Tax::factory()->create(['rate' => 0.05]);

        $this->save($other, ['is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->is_active);

        $this->save($vat, ['is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($vat->fresh()->is_active);
        $this->assertFalse($vat->fresh()->is_default);
    }
}
