<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The last resort, run on the server: a password for someone nobody in the
 * app can reset (e.g. the only Super Admin). The person must choose their
 * own password at the next sign-in, exactly as after an HR reset.
 *
 *   php artisan hrms:reset-password admin@company.com
 *   php artisan hrms:reset-password admin@company.com --password=12345
 */
class ResetUserPassword extends Command
{
    protected $signature = 'hrms:reset-password {email : The login email} {--password= : The temporary password (blank: one is made up)}';

    protected $description = 'Give a login a new temporary password (they choose their own at next sign-in)';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No login with that email.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(12, symbols: false));

        if (strlen($password) < 4) {
            $this->error('Use at least 4 characters.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
            'temporary_password' => filled(config('app.key')) ? $password : null,
            'status' => User::STATUS_ACTIVE,
        ])->save();
        $user->tokens()->delete();

        $this->info("Password reset for {$user->email}");
        $this->line("Temporary password: {$password}");
        $this->line('They will be asked to choose their own password at the next sign-in.');

        return self::SUCCESS;
    }
}
