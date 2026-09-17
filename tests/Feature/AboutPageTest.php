<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Help -> About in the desktop menu bar is the only Help entry there is, and
 * the version it shows is what support asks for over the phone. This is here
 * so a menu link can never again point at a route that does not exist.
 */
class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_shows_the_build_identity(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/about')
            ->assertOk()
            ->assertSee(config('app.name'))
            ->assertSee(config('nativephp.version'))
            ->assertSee(config('nativephp.app_id'));
    }

    public function test_about_is_behind_auth(): void
    {
        // A provisioned machine — otherwise RequiresSetup wins and sends
        // every route to /setup, which is its own tested behaviour.
        User::factory()->create();

        $this->get('/about')->assertRedirect('/login');
    }
}
