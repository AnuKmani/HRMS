<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3's proof that RBAC from Phase 2 is enforced on the server rather than
 * only being hidden by the Flutter UI: a real route, a real `permission:` gate,
 * and a real 403 when it denies.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_me_reflects_the_permissions_granted_through_the_users_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Payroll Admin');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $permissions = $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('data.permissions');

        $this->assertContains('payroll.view', $permissions);
        $this->assertContains('payroll.manage', $permissions);
        $this->assertNotContains('employees.delete', $permissions);
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee'); // has dashboard/attendance/leave only

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $response = $this->withToken($token)->getJson('/api/v1/roles');

        $response->assertForbidden();

        $this->assertFalse($response->json('success'));
        $this->assertIsString($response->json('message'));
        $this->assertNotEmpty($response->json('message'));
        $this->assertSame([], $response->json('errors'));
    }

    public function test_a_role_with_the_permission_is_allowed(): void
    {
        $user = User::factory()->create();
        $user->assignRole('HR Admin');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $response = $this->withToken($token)->getJson('/api/v1/roles');

        $response->assertOk()->assertJsonPath('success', true);

        $names = collect($response->json('data'))->pluck('name');
        $this->assertCount(10, $names);
        $this->assertContains('HR Admin', $names->all());
        $this->assertContains('Super Admin', $names->all());

        $superAdmin = collect($response->json('data'))->firstWhere('name', 'Super Admin');
        // Super Admin is seeded as ['*'], so all four spot-checks — spread
        // across four modules — must resolve to concrete permissions.
        $this->assertEqualsCanonicalizing(
            ['audit.view', 'payroll.manage', 'settings.manage', 'employees.delete'],
            array_values(array_intersect(
                ['audit.view', 'payroll.manage', 'settings.manage', 'employees.delete'],
                $superAdmin['permissions']
            ))
        );
        $this->assertCount(39, $superAdmin['permissions']);
    }

    public function test_a_user_with_no_roles_at_all_is_refused(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/roles')->assertForbidden();
    }

    public function test_the_roles_endpoint_requires_a_token_before_it_considers_permissions(): void
    {
        User::factory()->create();

        // 401, not 403: authentication failures must not leak the existence of
        // a permission-gated route.
        $this->getJson('/api/v1/roles')->assertUnauthorized();
    }

    public function test_the_permission_gate_is_not_bypassed_by_calling_a_different_method(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Management');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');

        // Same path, unsupported verb → 405 rather than 200 with data.
        $this->withToken($token)->postJson('/api/v1/roles')->assertStatus(405);
    }
}
