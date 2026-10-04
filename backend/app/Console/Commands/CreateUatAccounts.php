<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One sign-in per role, for local UAT of permission boundaries.
 *
 * Every account is an ordinary user holding exactly one seeded role, so what
 * it sees is what the RBAC seeders grant that role and nothing else — the
 * command never touches a permission. It also never invents a name for a
 * real person: each account is called "UAT <role>" and lives on a domain the
 * operator chooses.
 *
 * Re-running is safe. An existing account keeps its password unless
 * `--reset-passwords` is given, and its role is re-synced so a hand-edited
 * account drifts back to the role it is meant to represent.
 *
 * Refuses to run in production: a set of known logins with predictable
 * addresses is a convenience on a laptop and a liability on a live server.
 */
class CreateUatAccounts extends Command
{
    protected $signature = 'hrms:uat-accounts
        {--domain=uat.local : Email domain for the generated accounts}
        {--password= : Use this password for every account instead of generating one each}
        {--reset-passwords : Also set a new password on accounts that already exist}
        {--without-employees : Do not create an employee record behind each account}';

    protected $description = 'Create or refresh one UAT login per role (non-production only)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to create UAT accounts in production.');

            return self::FAILURE;
        }

        $domain = strtolower(trim((string) $this->option('domain')));

        if ($domain === '' || ! filter_var("probe@{$domain}", FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$domain}' is not a usable email domain.");

            return self::FAILURE;
        }

        $fixedPassword = $this->option('password');

        if ($fixedPassword !== null && strlen((string) $fixedPassword) < 8) {
            $this->error('--password must be at least 8 characters.');

            return self::FAILURE;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $missing = array_values(array_diff(
            RoleSeeder::ROLES,
            Role::query()->pluck('name')->all(),
        ));

        if ($missing !== []) {
            $this->error('Roles are not seeded: '.implode(', ', $missing));
            $this->line('Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $rows = [];
        $empty = [];

        foreach (RoleSeeder::ROLES as $index => $roleName) {
            $slug = Str::slug($roleName, '.');
            $email = "uat.{$slug}@{$domain}";

            [$user, $password] = DB::transaction(
                fn () => $this->upsertAccount($roleName, $email, $index + 1, $fixedPassword),
            );

            $count = $user->getAllPermissions()->count();

            if ($count === 0) {
                $empty[] = $roleName;
            }

            $rows[] = [$roleName, $email, $password ?? '(unchanged)', $count];
        }

        $this->table(['Role', 'Email', 'Password', 'Permissions'], $rows);
        $this->warn('Passwords are shown once and are not stored in plain text. Keep them out of version control.');

        if ($empty !== []) {
            // A role with no permissions renders a dashboard with only the
            // ungated tiles (Holidays) — exactly the symptom this exists to
            // explain rather than hide.
            $this->error('These roles hold no permissions: '.implode(', ', $empty));
            $this->line('Run `php artisan db:seed --class=PermissionSeeder` and `--class=RolePermissionSeeder`.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: User, 1: string|null} the account, and the password if one was set
     */
    private function upsertAccount(string $roleName, string $email, int $ordinal, ?string $fixedPassword): array
    {
        $user = User::query()->where('email', $email)->first();
        $password = null;

        if ($user === null || $this->option('reset-passwords')) {
            $password = $fixedPassword ?? Str::password(16, symbols: false);
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => "UAT {$roleName}",
                'email' => $email,
                'password' => Hash::make($password),
                'status' => User::STATUS_ACTIVE,
            ]);
        } elseif ($password !== null) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            // A new password ends every session issued under the old one.
            $user->tokens()->delete();
        }

        $user->syncRoles([$roleName]);

        if (! $this->option('without-employees') && $user->employee()->doesntExist()) {
            Employee::query()->create([
                'user_id' => $user->id,
                'employee_code' => sprintf('UAT-%03d', $ordinal),
                'first_name' => 'UAT',
                'last_name' => $roleName,
                'email' => $email,
                'joining_date' => now()->toDateString(),
                'employment_status' => Employee::STATUS_ACTIVE,
            ]);
        }

        return [$user->refresh(), $password];
    }
}
