<?php

namespace Tests\Feature\Demo;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** BUG-046: the demo key lock follows the General + Currency groups of the settings type map. */
class DemoLockedKeysTest extends TestCase
{
    use RefreshDatabase;

    private function submit(string $group, array $settings)
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.update'), ['group' => $group, 'settings' => $settings]);
    }

    public function test_outside_a_demo_the_store_language_is_still_saved_from_the_general_tab(): void
    {
        config(['pos.demo_mode' => false]);

        $this->submit('general', ['language' => 'ar'])->assertSessionHasNoErrors();

        $this->assertSame('ar', Setting::get('language'));
    }

    public function test_in_a_demo_language_is_refused_whatever_the_group_but_other_keys_still_save(): void
    {
        config(['pos.demo_mode' => true]);
        Setting::set('language', 'en', 'general');

        foreach (['general', 'appearance', 'pos'] as $group) {
            $this->submit($group, ['language' => 'ar'])->assertSessionHasErrors('demo');
        }
        $this->assertSame('en', Setting::get('language'));

        $this->submit('pos', ['idle_timeout_min' => '15'])->assertSessionHasNoErrors();
        $this->assertEquals(15, Setting::get('idle_timeout_min'));
    }
}
