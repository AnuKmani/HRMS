<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HR issues a new joiner's login and chooses their role; the role is then
 * the whole of what they may do.
 */
class EmployeeAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function signInAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function joiner(): Employee
    {
        return Employee::factory()->create([
            'user_id' => null,
            'email' => 'new.joiner@example.com',
        ]);
    }

    public function test_hr_creates_a_project_manager_login_who_can_then_add_sites(): void
    {
        $this->signInAs('HR Admin');
        $employee = $this->joiner();

        $response = $this->postJson("/api/v1/employees/{$employee->id}/account", [
            'role' => 'Project Manager',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'new.joiner@example.com')
            ->assertJsonPath('data.roles', ['Project Manager']);

        $password = $response->json('data.temporary_password');
        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(12, strlen($password));

        $user = User::query()->where('email', 'new.joiner@example.com')->sole();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame($user->id, $employee->refresh()->user_id);

        // The role, not this endpoint, is what lets them add a site.
        $this->assertTrue($user->can('sites.manage'));

        $this->postJson('/api/v1/auth/login', [
            'email' => 'new.joiner@example.com',
            'password' => $password,
        ])->assertOk();

        $this->assertTrue(AuditLog::query()->where('action', 'user.account_created')->exists());
    }

    public function test_an_employee_role_cannot_add_sites(): void
    {
        $this->signInAs('HR Admin');
        $employee = $this->joiner();

        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Employee'])
            ->assertCreated();

        $user = User::query()->where('email', 'new.joiner@example.com')->sole();
        $this->assertFalse($user->can('sites.manage'));
    }

    public function test_hr_changes_the_role_later_and_it_is_audited(): void
    {
        $this->signInAs('HR Admin');
        $employee = $this->joiner();
        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Employee'])->assertCreated();

        $this->putJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Site Supervisor'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['Site Supervisor']);

        $log = AuditLog::query()->where('action', 'user.role_changed')->sole();
        $this->assertSame(['Employee'], $log->old_values['roles']);
        $this->assertSame(['Site Supervisor'], $log->new_values['roles']);
    }

    public function test_a_second_login_for_the_same_employee_is_refused(): void
    {
        $this->signInAs('HR Admin');
        $employee = $this->joiner();
        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Employee'])->assertCreated();

        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Employee'])
            ->assertStatus(409);
    }

    public function test_hr_cannot_hand_out_super_admin(): void
    {
        $this->signInAs('HR Admin');
        $employee = $this->joiner();

        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Super Admin'])
            ->assertForbidden();
        $this->assertNull($employee->refresh()->user_id);
    }

    public function test_a_super_admin_can(): void
    {
        $this->signInAs('Super Admin');
        $employee = $this->joiner();

        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Super Admin'])
            ->assertCreated();
    }

    public function test_an_unknown_role_is_a_validation_error(): void
    {
        $this->signInAs('HR Admin');
        $employee = $this->joiner();

        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Wizard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_roles_without_users_manage_are_refused(): void
    {
        $employee = $this->joiner();

        foreach (['HR Executive', 'Project Manager', 'Employee'] as $role) {
            $this->signInAs($role);
            $this->postJson("/api/v1/employees/{$employee->id}/account", ['role' => 'Employee'])
                ->assertForbidden();
        }
    }
}
