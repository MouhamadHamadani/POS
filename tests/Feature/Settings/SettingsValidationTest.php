<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings > save validates the numeric settings server-side (BUG-007). */
class SettingsValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_rate_is_saved(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'currency',
            'settings' => ['exchange_rate' => '89500', 'lbp_rounding_step' => '500'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(89500.0, Setting::get('exchange_rate'));
        $this->assertSame(500.0, Setting::get('lbp_rounding_step'));
    }

    public function test_a_bad_rate_is_refused_with_the_translated_message_in_both_languages(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('exchange_rate', 90000, 'currency', 'float');

        foreach (['en', 'ar'] as $locale) {
            $admin->update(['language' => $locale]);
            $this->actingAs($admin->fresh())->post(route('settings.update'), [
                'group' => 'currency',
                'settings' => ['exchange_rate' => '0'],
            ])->assertSessionHasErrors(['settings.exchange_rate' => trans('messages.exchange_rate_invalid', [], $locale)]);
        }

        $this->assertSame(90000.0, Setting::get('exchange_rate'));
    }

    public function test_the_other_numeric_settings_keep_to_the_ranges_the_form_advertises(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('max_cashier_discount_pct', 10, 'pos', 'float');
        Setting::set('idle_timeout_min', 15, 'pos', 'int');

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'pos',
            'settings' => ['max_cashier_discount_pct' => '150', 'idle_timeout_min' => '0'],
        ])->assertSessionHasErrors(['settings.max_cashier_discount_pct', 'settings.idle_timeout_min']);

        $this->assertSame(10.0, Setting::get('max_cashier_discount_pct'));
        $this->assertSame(15, Setting::get('idle_timeout_min'));
    }
}
