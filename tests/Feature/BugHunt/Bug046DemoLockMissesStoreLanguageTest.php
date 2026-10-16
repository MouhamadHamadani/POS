<?php

namespace Tests\Feature\BugHunt;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-046: the demo build's lock by key (BUG-023) leaves out `language`.
 *
 * SettingController::DEMO_LOCKED_KEYS is a hand-copied list of the keys on the locked General and
 * Currency tabs, and it omits `language`, a field of the General tab. With demo mode on,
 * `group=pos` + `settings[language]=ar` is accepted and switches the store language of a demo
 * that is meant to start identical on every launch. Every key the locked tabs carry must be refused
 * whatever group it arrives under; the second test derives the key list from the rendered forms so
 * a key added to those tabs later cannot be missed either.
 */
class Bug046DemoLockMissesStoreLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_store_language_cannot_be_changed_in_a_demo_build_through_the_pos_group(): void
    {
        config(['pos.demo_mode' => true]);
        Setting::set('language', 'en', 'general');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'pos',
            'settings' => ['language' => 'ar', 'idle_timeout_min' => '15'],
        ]);

        $this->assertSame('en', Setting::get('language'), 'The demo store language was changed.');
    }

    public function test_no_key_of_the_locked_general_and_currency_tabs_can_be_changed_in_a_demo_build_under_another_group(): void
    {
        config(['pos.demo_mode' => false]);
        $admin = User::factory()->admin()->create();

        // Every settings[...] control of the two locked tabs, as the real forms render them.
        $keys = [];
        foreach (['general', 'currency'] as $tab) {
            $html = $this->actingAs($admin)->get(route('settings.index', ['tab' => $tab]))->assertOk()->getContent();
            preg_match_all('/name="settings\[(\w+)\]"/', $html, $found);
            $keys = array_merge($keys, $found[1]);
        }
        $keys = array_values(array_unique($keys));
        $this->assertContains('exchange_rate', $keys, 'Precondition: the Currency tab was rendered.');
        $this->assertContains('business_name', $keys, 'Precondition: the General tab was rendered.');

        $numeric = ['exchange_rate' => 90000.0, 'lbp_rounding_step' => 1000.0];
        $changed = fn (string $key) => match (true) {
            $key === 'language' => 'ar',
            isset($numeric[$key]) => '12345',
            default => "changed-{$key}",
        };
        foreach ($keys as $key) {
            isset($numeric[$key])
                ? Setting::set($key, $numeric[$key], 'currency', 'float')
                : Setting::set($key, $key === 'language' ? 'en' : "original-{$key}", 'general');
        }

        config(['pos.demo_mode' => true]);
        $notLocked = [];
        foreach ($keys as $key) {
            $before = Setting::get($key);
            $this->actingAs($admin)->post(route('settings.update'), [
                'group' => 'pos',
                'settings' => [$key => $changed($key)],
            ]);
            if (Setting::get($key) !== $before) {
                $notLocked[] = $key;
            }
        }

        $this->assertSame([], $notLocked, 'These keys of the demo-locked tabs were changed in a demo build: '.implode(', ', $notLocked));
    }
}
