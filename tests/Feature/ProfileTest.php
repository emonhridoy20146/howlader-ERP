<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_profile(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->get(route('profile.password.edit'))->assertRedirect(route('login'));
    }

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'oldname',
            'email' => 'old@example.com',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => 'newname',
                'full_name' => 'New Full',
                'email' => 'new@example.com',
                'phone' => '555-0100',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame('newname', $user->name);
        $this->assertSame('New Full', $user->full_name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertSame('555-0100', $user->phone);
    }

    public function test_user_can_change_password_with_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-secret-8'),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('profile.password.update'), [
                'current_password' => 'old-secret-8',
                'password' => 'new-secret-9',
                'password_confirmation' => 'new-secret-9',
            ])
            ->assertRedirect(route('profile.password.edit'))
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-9', $user->password));
    }

    public function test_password_change_fails_with_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-pass-8'),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('profile.password.edit'))
            ->put(route('profile.password.update'), [
                'current_password' => 'wrong-pass-8',
                'password' => 'new-secret-9',
                'password_confirmation' => 'new-secret-9',
            ])
            ->assertRedirect(route('profile.password.edit'))
            ->assertSessionHasErrors('current_password');
    }
}
