<?php

namespace Tests\Feature\BugHunt;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG-023: the demo build's lock on business identity and currency is bypassed by posting the keys under a different group.
 *
 * SettingController::update() refuses a demo request only when the posted `group` is `general` or
 * `currency`. Which keys get written is decided separately, by key name, from one shared map. So
 * `group=pos` with `settings[exchange_rate]` or `settings[business_name]` sails past the lock and is
 * saved: the demo's baseline (the very numbers the demo exists to show) can be changed.
 */
class Bug023DemoLockBypassedByPostingToAnotherGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_rate_and_business_name_cannot_be_changed_in_a_demo_build_through_the_pos_group(): void
    {
        config(['pos.demo_mode' => true]);
        Setting::set('exchange_rate', 90000, 'currency', 'float');
        Setting::set('business_name', 'LebaSouk Demo', 'general');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.update'), [
            'group' => 'pos',
            'settings' => ['exchange_rate' => '12345', 'business_name' => 'Somebody Real', 'idle_timeout_min' => '15'],
        ]);

        $this->assertEquals(90000, Setting::get('exchange_rate'), 'The demo exchange rate was changed.');
        $this->assertSame('LebaSouk Demo', Setting::get('business_name'), 'The demo business name was changed.');
    }
}
