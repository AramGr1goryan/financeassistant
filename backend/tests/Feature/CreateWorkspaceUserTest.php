<?php

namespace Tests\Feature;

use App\Models\TelegramOwnerLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateWorkspaceUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_interactive_command_creates_a_user_with_a_hashed_password(): void
    {
        $this->artisan('finance:create-user')
            ->expectsQuestion('Name', 'Test Owner')
            ->expectsQuestion('Email', 'OWNER@example.test')
            ->expectsQuestion('Password (at least 12 characters, at most 72 bytes)', 'test-password-123')
            ->expectsQuestion('Confirm password', 'test-password-123')
            ->expectsOutput('Workspace user created.')
            ->assertExitCode(0);

        $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame('Test Owner', $user->name);
        $this->assertTrue(Hash::check('test-password-123', $user->password));
        $this->assertNotSame('test-password-123', $user->password);
    }

    public function test_provisioned_user_can_sign_in_to_the_existing_session_endpoint(): void
    {
        $this->artisan('finance:create-user')
            ->expectsQuestion('Name', 'Test Owner')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password (at least 12 characters, at most 72 bytes)', 'test-password-123')
            ->expectsQuestion('Confirm password', 'test-password-123')
            ->assertExitCode(0);

        $this->postJson('/api/v1/session', [
            'email' => 'owner@example.test',
            'password' => 'test-password-123',
        ])->assertNoContent();
        $this->getJson('/api/v1/balance?currency=AMD&currency_exponent=0')
            ->assertOk()->assertJsonPath('data.owned_funds.minor_units', '0');
    }

    public function test_provisioned_user_is_linked_to_the_configured_telegram_owner(): void
    {
        config(['services.telegram.owner_id' => 777000111]);

        $this->artisan('finance:create-user')
            ->expectsQuestion('Name', 'Test Owner')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password (at least 12 characters, at most 72 bytes)', 'test-password-123')
            ->expectsQuestion('Confirm password', 'test-password-123')
            ->assertExitCode(0);

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertDatabaseHas('telegram_owner_links', [
            'owner_id' => $owner->id,
            'telegram_user_id' => 777000111,
            'telegram_chat_id' => 777000111,
            'is_active' => true,
        ]);
        $this->assertNotNull(TelegramOwnerLink::query()->where('owner_id', $owner->id)->value('verified_at'));
    }

    public function test_noninteractive_invocation_is_rejected(): void
    {
        $this->artisan('finance:create-user', ['--no-interaction' => true])
            ->expectsOutput('This command requires an interactive terminal.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_email_is_rejected_before_password_prompt(): void
    {
        $this->artisan('finance:create-user')
            ->expectsQuestion('Name', 'Owner')
            ->expectsQuestion('Email', 'invalid')
            ->expectsOutput('A valid name and email are required.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_email_is_rejected_without_overwriting_user(): void
    {
        $existing = User::factory()->create(['email' => 'owner@example.test']);
        $originalHash = $existing->password;

        $this->artisan('finance:create-user')
            ->expectsQuestion('Name', 'Another Owner')
            ->expectsQuestion('Email', 'OWNER@example.test')
            ->expectsOutput('A user with this email already exists.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalHash, $existing->fresh()->password);
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->artisan('finance:create-user')
            ->expectsQuestion('Name', 'Owner')
            ->expectsQuestion('Email', 'owner@example.test')
            ->expectsQuestion('Password (at least 12 characters, at most 72 bytes)', 'test-password-123')
            ->expectsQuestion('Confirm password', 'another-password')
            ->expectsOutput('Passwords do not match.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_short_and_overlong_passwords_are_rejected(): void
    {
        foreach (['short', str_repeat('x', 73)] as $password) {
            $this->artisan('finance:create-user')
                ->expectsQuestion('Name', 'Owner')
                ->expectsQuestion('Email', 'owner@example.test')
                ->expectsQuestion('Password (at least 12 characters, at most 72 bytes)', $password)
                ->expectsQuestion('Confirm password', $password)
                ->expectsOutput('Password must be at least 12 characters and at most 72 bytes.')
                ->assertExitCode(1);
        }

        $this->assertDatabaseCount('users', 0);
    }
}
