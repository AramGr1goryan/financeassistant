<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('finance:change-password {email? : Email of the workspace user}')]
#[Description('Securely change a workspace user password from the interactive terminal')]
class ChangeUserPassword extends Command
{
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('This command requires an interactive terminal.');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        if (! is_string($email) || trim($email) === '') {
            $email = mb_strtolower(trim((string) $this->ask('User email')));
        } else {
            $email = mb_strtolower(trim($email));
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user === null) {
            $this->error("User with email '{$email}' not found.");

            return self::FAILURE;
        }

        $newPassword = $this->secret('New password (at least 12 characters, at most 72 bytes)', false);
        $confirmation = $this->secret('Confirm new password', false);

        if (! is_string($newPassword) || mb_strlen($newPassword) < 12 || strlen($newPassword) > 72) {
            $this->error('Password must be between 12 characters and 72 bytes.');

            return self::FAILURE;
        }

        if (! is_string($confirmation) || ! hash_equals($newPassword, $confirmation)) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();

        $this->info("Password updated successfully for '{$user->email}'.");

        return self::SUCCESS;
    }
}
