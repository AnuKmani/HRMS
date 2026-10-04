<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UatAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedRbac(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_creates_one_account_per_role_with_an_employee_record(): void
    {
        $this->seedRbac();

        $this->artisan('hrms:uat-accounts', ['--password' => 'Uat-pass-123'])
            ->assertSuccessful();

        foreach (RoleSeeder::ROLES as $role) {
            $user = User::query()->whereHas('roles', fn ($q) => $q->where('name', $role))->sole();

            $this->assertSame([$role], $user->getRoleNames()->all());
            $this->assertTrue(Hash::check('Uat-pass-123', $user->password));
            $this->assertNotNull($user->employee);
            $this->assertGreaterThan(0, $user->getAllPermissions()->count());
        }
    }

    public function test_super_admin_account_signs_in_with_every_permission(): void
    {
        $this->seedRbac();
        $this->artisan('hrms:uat-accounts', ['--password' => 'Uat-pass-123'])->assertSuccessful();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'uat.super.admin@uat.local',
            'password' => 'Uat-pass-123',
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            PermissionSeeder::flat(),
            $response->json('data.user.permissions'),
        );
    }

    public function test_rerun_keeps_existing_passwords_unless_asked(): void
    {
        $this->seedRbac();
        $this->artisan('hrms:uat-accounts', ['--password' => 'First-pass-123'])->assertSuccessful();
        $this->artisan('hrms:uat-accounts', ['--password' => 'Second-pass-123'])->assertSuccessful();

        $user = User::query()->where('email', 'uat.employee@uat.local')->sole();
        $this->assertTrue(Hash::check('First-pass-123', $user->password));
        $this->assertSame(count(RoleSeeder::ROLES), User::query()->count());

        $this->artisan('hrms:uat-accounts', ['--password' => 'Second-pass-123', '--reset-passwords' => true])
            ->assertSuccessful();
        $this->assertTrue(Hash::check('Second-pass-123', $user->refresh()->password));
    }

    public function test_fails_loudly_when_roles_hold_no_permissions(): void
    {
        // Roles without RolePermissionSeeder: the "dashboard shows only
        // Holidays" state. The command must say so, not report success.
        $this->seed(RoleSeeder::class);

        $this->artisan('hrms:uat-accounts', ['--password' => 'Uat-pass-123'])
            ->expectsOutputToContain('hold no permissions')
            ->assertFailed();
    }

    public function test_fails_when_roles_are_not_seeded(): void
    {
        $this->artisan('hrms:uat-accounts')->assertFailed();
        $this->assertSame(0, User::query()->count());
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->seedRbac();
        $this->app['env'] = 'production';

        $this->artisan('hrms:uat-accounts')->assertFailed();
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_database_seeded_before_new_roles_were_added_does_not_collide(): void
    {
        $this->seedRbac();

        // What an earlier run left behind: the old ten-role list numbered
        // Finance 8, Management 9 and Employee 10, so UAT-010 is taken by an
        // account that is not the one now tenth in the list.
        foreach (['UAT-008' => 'finance', 'UAT-009' => 'management', 'UAT-010' => 'employee'] as $code => $slug) {
            $user = User::factory()->create(['email' => "uat.{$slug}@uat.local"]);
            Employee::factory()->create(['user_id' => $user->id, 'employee_code' => $code]);
        }
        // A soft-deleted row still holds its code in the unique index.
        Employee::factory()->create(['employee_code' => 'UAT-011'])->delete();

        $this->artisan('hrms:uat-accounts', ['--password' => 'Uat-pass-123', '--reset-passwords' => true])
            ->assertSuccessful();

        foreach (RoleSeeder::ROLES as $role) {
            $user = User::query()->whereHas('roles', fn ($q) => $q->where('name', $role))->sole();
            $this->assertNotNull($user->employee, $role);
            $this->assertTrue(Hash::check('Uat-pass-123', $user->password), $role);
        }
    }
}
