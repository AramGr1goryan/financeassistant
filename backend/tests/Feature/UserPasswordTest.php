<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_update_password(): void
    {
        $this->putJson('/api/v1/user/password', [
            'current_password' => 'old-password-123',
            'new_password' => 'new-password-456',
            'new_password_confirmation' => 'new-password-456',
        ])->assertUnauthorized();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password-123'),
        ]);

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/user/password', [
                'current_password' => 'wrong-password-999',
                'new_password' => 'brand-new-secret-123',
                'new_password_confirmation' => 'brand-new-secret-123',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('correct-password-123', $user->fresh()->password));
    }

    public function test_password_length_and_confirmation_must_be_valid(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password-123'),
        ]);

        // Too short (<12 chars)
        $this->actingAs($user, 'web')
            ->putJson('/api/v1/user/password', [
                'current_password' => 'correct-password-123',
                'new_password' => 'short',
                'new_password_confirmation' => 'short',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);

        // Confirmation mismatch
        $this->actingAs($user, 'web')
            ->putJson('/api/v1/user/password', [
                'current_password' => 'correct-password-123',
                'new_password' => 'new-password-long-enough-123',
                'new_password_confirmation' => 'different-confirmation-123',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);
    }

    public function test_owner_can_successfully_update_password_and_login_with_new_password(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => Hash::make('old-secret-password-123'),
        ]);

        $this->actingAs($user, 'web')
            ->putJson('/api/v1/user/password', [
                'current_password' => 'old-secret-password-123',
                'new_password' => 'new-safe-password-99999',
                'new_password_confirmation' => 'new-safe-password-99999',
            ])->assertOk();

        // Database updated
        $this->assertTrue(Hash::check('new-safe-password-99999', $user->fresh()->password));
        $this->assertFalse(Hash::check('old-secret-password-123', $user->fresh()->password));

        // Attempt login with old password fails
        $this->postJson('/api/v1/session', [
            'email' => 'owner@example.com',
            'password' => 'old-secret-password-123',
        ])->assertStatus(422);

        // Attempt login with new password succeeds
        $this->postJson('/api/v1/session', [
            'email' => 'owner@example.com',
            'password' => 'new-safe-password-99999',
        ])->assertNoContent();
    }
}

