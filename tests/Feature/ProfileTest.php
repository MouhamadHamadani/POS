<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'language' => 'ar',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();
        $this->assertSame('Test User', $user->name);
        $this->assertSame('testuser', $user->username);
        $this->assertSame('ar', $user->language);
    }

    public function test_username_must_be_unique(): void
    {
        $existing = User::factory()->create(['username' => 'taken']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'username' => 'taken',
            'language' => 'en',
        ]);

        $response->assertSessionHasErrors('username');
    }

    public function test_pin_can_be_set_with_correct_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/profile/pin', [
            'current_password' => 'password',
            'pin' => '1234',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('1234', $user->fresh()->pin));
    }

    public function test_pin_update_rejected_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/profile/pin', [
            'current_password' => 'WRONG',
            'pin' => '1234',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertNull($user->fresh()->pin);
    }

    public function test_delete_profile_route_is_removed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/profile')->assertStatus(405); // route removed; only PATCH allowed
    }
}
