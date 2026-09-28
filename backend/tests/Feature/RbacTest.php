<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wipes the schema, so RBAC must be re-seeded per test.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    /* ------------------------------------------------------------ roles */

    public function test_all_ten_roles_are_created(): void
    {
        $this->assertCount(10, Role::all());

        foreach (RoleSeeder::ROLES as $role) {
            $this->assertDatabaseHas('roles', ['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_role_seeder_is_idempotent(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertCount(10, Role::all());
    }

    /* ------------------------------------------------------ permissions */

    public function test_permission_catalog_is_seeded(): void
    {
        $expected = PermissionSeeder::flat();

        $this->assertCount(count($expected), Permission::all());

        foreach ($expected as $permission) {
            $this->assertTrue(
                Permission::where('name', $permission)->exists(),
                "Missing permission: {$permission}",
            );
        }
    }

    public function test_required_example_permissions_exist(): void
    {
        $required = [
            'employees.view', 'employees.create', 'employees.update', 'employees.delete',
            'attendance.view', 'attendance.manage',
            'leave.approve',
            'payroll.view', 'payroll.manage',
            'projects.manage', 'sites.manage',
            'reports.view', 'documents.manage',
            'expenses.approve', 'audit.view',
        ];

        foreach ($required as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission]);
        }
    }

    public function test_permissions_follow_resource_action_convention(): void
    {
        foreach (PermissionSeeder::flat() as $permission) {
            // Two segments (`employees.view`) or three (`employees.salary.view`).
            // The third is reserved for a sub-field of a resource — salary is
            // the case that forced it: it must not be implied by employees.view.
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+(\.[a-z_]+)+$/',
                $permission,
                "Permission '{$permission}' must be lowercase segments joined by dots",
            );

            $this->assertLessThanOrEqual(
                3,
                substr_count($permission, '.') + 1,
                "Permission '{$permission}' may have at most three segments",
            );
        }
    }

    /* ------------------------------------------- role/permission mapping */

    public function test_super_admin_holds_every_permission(): void
    {
        $superAdmin = Role::findByName('Super Admin', 'web');

        $this->assertCount(Permission::count(), $superAdmin->permissions);
        $this->assertTrue($superAdmin->hasPermissionTo('payroll.manage'));
        $this->assertTrue($superAdmin->hasPermissionTo('employees.delete'));
    }

    public function test_employee_role_is_deny_by_default(): void
    {
        $employee = Role::findByName('Employee', 'web');

        foreach (['payroll.manage', 'employees.delete', 'attendance.manage', 'leave.approve', 'audit.view'] as $denied) {
            $this->assertFalse(
                $employee->hasPermissionTo($denied),
                "Employee must not hold {$denied}",
            );
        }

        $this->assertTrue($employee->hasPermissionTo('leave.create'));
        $this->assertTrue($employee->hasPermissionTo('leave.balance.view'));
        $this->assertFalse($employee->hasPermissionTo('leave.balance.manage'));
    }

    public function test_role_permission_grants_are_reproducible(): void
    {
        $first = Role::findByName('HR Admin', 'web')->permissions->pluck('name')->sort()->values();

        $this->seed(RolePermissionSeeder::class);

        $second = Role::findByName('HR Admin', 'web')->permissions->pluck('name')->sort()->values();

        $this->assertTrue($first->all() === $second->all());
        $this->assertContains('employees.create', $second->all());
        // Phase 8 grants HR Admin the payroll *preparation* grants - see
        // RolePermissionSeeder - and withholds exactly one: the lock, which
        // is the only irreversible act in the module and is checked here
        // rather than assumed from the absence of an unlock endpoint.
        $this->assertContains('payroll.manage', $second->all());
        $this->assertContains('payroll.process', $second->all());
        $this->assertNotContains('payroll.lock', $second->all());
    }

    /* -------------------------------------------------- user-level gate */

    public function test_user_inherits_permissions_from_assigned_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('HR Executive');

        $this->assertTrue($user->can('employees.create'));
        $this->assertTrue($user->can('leave.approve'));
        $this->assertFalse($user->can('employees.delete'));
        $this->assertFalse($user->can('payroll.manage'));
    }

    public function test_user_without_role_holds_no_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasAnyRole(RoleSeeder::ROLES));
        $this->assertFalse($user->can('employees.view'));
        $this->assertFalse($user->can('dashboard.view'));
    }

    public function test_roles_can_be_swapped_and_permissions_follow(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Site Supervisor');
        $this->assertTrue($user->can('attendance.manage'));

        $user->removeRole('Site Supervisor');
        $user->assignRole('Site Engineer');

        $this->assertFalse($user->can('attendance.manage'));
        $this->assertTrue($user->can('sites.view'));
    }

    /* ------------------------------------- server-side route enforcement */

    public function test_permission_middleware_blocks_unauthorized_user(): void
    {
        // Only a foundation route exists this phase — registering it here
        // proves the middleware alias is wired up and actually denies.
        Route::prefix('api')->middleware(['api', 'auth:sanctum', 'permission:payroll.manage'])
            ->get('/_rbac_probe', fn () => response()->json(['ok' => true]));

        $user = User::factory()->create();
        $user->assignRole('Site Engineer');
        Sanctum::actingAs($user);

        $this->getJson('/api/_rbac_probe')->assertForbidden();
    }

    public function test_permission_middleware_allows_authorized_user(): void
    {
        Route::prefix('api')->middleware(['api', 'auth:sanctum', 'permission:payroll.manage'])
            ->get('/_rbac_probe', fn () => response()->json(['ok' => true]));

        $user = User::factory()->create();
        $user->assignRole('Payroll Admin');
        Sanctum::actingAs($user);

        $this->getJson('/api/_rbac_probe')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_unauthenticated_request_cannot_reach_permission_protected_route(): void
    {
        Route::prefix('api')->middleware(['api', 'auth:sanctum', 'permission:payroll.manage'])
            ->get('/_rbac_probe', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/_rbac_probe')->assertUnauthorized();
    }

    public function test_role_middleware_enforces_named_role(): void
    {
        Route::prefix('api')->middleware(['api', 'auth:sanctum', 'role:HR Admin|Super Admin'])
            ->get('/_role_probe', fn () => response()->json(['ok' => true]));

        $user = User::factory()->create();
        $user->assignRole('Finance');
        Sanctum::actingAs($user);

        $this->getJson('/api/_role_probe')->assertForbidden();
    }
}
