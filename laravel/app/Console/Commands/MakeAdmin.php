<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    protected $signature = 'admin:make {email : The email address of the admin to promote or create}';

    protected $description = 'Promote an existing user to admin, or create a new admin if no user with that email exists.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email'],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return self::INVALID;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            if ($user->is_admin) {
                $this->info("{$email} is already an admin.");

                return self::SUCCESS;
            }

            $user->forceFill(['is_admin' => true])->save();
            $this->info("promoted {$email} to admin");

            return self::SUCCESS;
        }

        // Create + promote.
        $defaultName = Str::of($email)->before('@')->headline()->toString();
        $name = (string) ($this->ask('Display name', $defaultName) ?? $defaultName);

        // `secret()` returns null under --no-interaction or empty input.
        $passwordInput = $this->secret('Password (leave blank to auto-generate a 16-char password)');
        $generated = $passwordInput === null || $passwordInput === '';
        $password = $generated ? Str::random(16) : (string) $passwordInput;

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
            'is_admin' => true,
        ])->save();

        $this->info("created and promoted {$email}");
        if ($generated) {
            $this->warn("password: {$password}");
            $this->line('Store this password now — it will not be shown again.');
        }

        return self::SUCCESS;
    }
}
