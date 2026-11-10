<?php

namespace App\Console\Commands;

use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

#[Signature('finance:create-user')]
#[Description('Create a workspace user using interactive hidden password input')]
class CreateWorkspaceUser extends Command
{
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command requires an interactive terminal.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $email = mb_strtolower(trim((string) $this->ask('Email')));
        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'string', 'email', 'max:255']],
        );

        if ($validator->fails()) {
            $this->error('A valid name and email are required.');

            return self::FAILURE;
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 12 characters, at most 72 bytes)', false);
        $confirmation = $this->secret('Confirm password', false);

        if (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72) {
            $this->error('Password must be at least 12 characters and at most 72 bytes.');

            return self::FAILURE;
        }

        if (! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password): void {
            $owner = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
            $telegramOwnerId = app(TelegramOwnerLinkService::class)->configuredOwnerTelegramId();

            if ($telegramOwnerId !== null) {
                app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, $telegramOwnerId);
            }
        });

        $this->info('Workspace user created.');

        return self::SUCCESS;
    }
}
