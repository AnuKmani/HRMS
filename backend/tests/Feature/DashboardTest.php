<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four role dashboards: /dashboards/{employee, hr, project-manager,
 * management}.
 *
 * Two mechanisms, deliberately, and the tests below are written around the
 * split rather than around the payloads:
 *
 *   - the **coarse gate** (`permission:dashboard.view`) on the route
 *     answers "may this account open a dashboard at all?" — one question,
 *     one answer, and a user with no role is refused before a single query
 *     runs;
 *   - the **per-block `can()`** inside `DashboardController` decides what a
 *     block may contain, because no combination of route middleware can
 *     express "this endpoint, but only three of its five cards".
 *
 * So an Employee may open the *HR* dashboard and be handed a payload whose
 * `available.workforce` is `false` — the client draws nothing for a switch
 * that is off. That is the design, not a leak: `available` is a map of what
 * the account may see, and every key in `blocks` is drawn from it.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        Sanctum::actingAs($user);

        return $user;
    }

    /* -------------------------------------------------------- the gate */

    public function test_a_dashboard_is_never_reachable_anonymously(): void
    {
        foreach (['employee', 'hr', 'project-manager', 'management'] as $role) {
            $this->getJson("/api/v1/dashboards/{$role}")->assertUnauthorized();
        }
    }

    public function test_the_coarse_gate_refuses_an_account_holding_nothing(): void
    {
        $user = User::factory()->create(); // no role, so no permissions

        Sanctum::actingAs($user);

        foreach (['employee', 'hr', 'project-manager', 'management'] as $role) {
            $this->getJson("/api/v1/dashboards/{$role}")->assertForbidden();
        }
    }

    /* ------------------------------------------------- what each shows */

    public function test_the_employee_dashboard_offers_only_what_an_employee_may_see(): void
    {
        $this->actingAsRole('Employee');

        $response = $this->getJson('/api/v1/dashboards/employee')->assertOk();

        $available = $response->json('data.available');

        $this->assertSame('employee', $response->json('data.role'));
        $this->assertTrue($available['attendance']);
        $this->assertTrue($available['leave']);
        $this->assertTrue($available['balances']);
        $this->assertTrue($available['payroll']);

        // Headcount is an HR question; the employee dashboard does not
        // even have a slot for it, rather than having one switched off.
        $this->assertArrayNotHasKey('workforce', $available);
        $this->assertArrayNotHasKey('compliance', $available);

        $this->assertTrue($response->json('data.available.notifications'));
        $this->assertIsInt($response->json('data.blocks.notifications.unread'));
        $this->assertNotEmpty($response->json('data.generated_at'));
    }

    public function test_opening_another_role_s_dashboard_changes_nothing_about_the_switches(): void
    {
        $this->actingAsRole('Employee');

        $response = $this->getJson('/api/v1/dashboards/hr')->assertOk();

        // The coarse gate admits them — `dashboard.view` is all it asks —
        // and the finer check inside then refuses each block in turn. One
        // gate would have had to guess which of the four URLs an Employee
        // may open, and guessing there is how a workforce headcount ends
        // up on a phone it has no business being on.
        $this->assertSame('hr', $response->json('data.role'));

        $available = $response->json('data.available');

        $this->assertFalse($available['workforce']);
        $this->assertFalse($available['payroll']);
        $this->assertFalse($available['compliance']);
        $this->assertTrue($available['notifications']);
    }

    public function test_hr_sees_the_headcount_a_company_dashboard_is_built_on(): void
    {
        Employee::factory()->count(3)->create();
        Employee::factory()->status('suspended')->create();

        $this->actingAsRole('HR Admin');

        $response = $this->getJson('/api/v1/dashboards/hr')->assertOk();

        $this->assertTrue($response->json('data.available.workforce'));

        // The workforce block returns by_status, total, and departments
        $workforce = $response->json('data.blocks.workforce');
        $this->assertArrayHasKey('by_status', $workforce);
        $this->assertArrayHasKey('total', $workforce);
        $this->assertArrayHasKey('departments', $workforce);
        $this->assertEquals(4, $workforce['total']);
    }

    public function test_a_project_manager_gets_sites_and_a_team_but_not_the_payroll(): void
    {
        $this->actingAsRole('Project Manager');

        $available = $this->getJson('/api/v1/dashboards/project-manager')
            ->assertOk()
            ->json('data.available');

        $this->assertTrue($available['sites']);
        $this->assertTrue($available['site_reports']);
        $this->assertTrue($available['approvals']);
        $this->assertTrue($available['team']);

        // Neither of the two company-wide blocks exists on this screen —
        // not as a switch set to false, but as no screen to switch. The
        // payroll block is HR's; a manager's kit is sites, reports and the
        // people assigned to them.
        $this->assertArrayNotHasKey('payroll', $available);
        $this->assertArrayNotHasKey('workforce', $available);
    }

    public function test_management_gets_payroll_as_aggregates_and_never_as_people(): void
    {
        Employee::factory()->create([
            'first_name' => 'Zoraida',
            'last_name' => 'Konstantinidis',
        ]);

        $this->actingAsRole('Management');

        $response = $this->getJson('/api/v1/dashboards/management')->assertOk();

        $payroll = $response->json('data.blocks.payroll');

        $this->assertTrue($response->json('data.available.payroll'));
        $this->assertIsArray($payroll);
        // PayrollService::summary() returns employee_count, not row_count
        $this->assertArrayHasKey('employee_count', $payroll);

        // `payroll.summary.view` is what the *aggregates* need; individual
        // salaries need `employees.salary.view`, which is a different and
        // much narrower permission. The block therefore has no row per
        // person to leak, and the one name on the system proves it.
        $body = $response->getContent();

        $this->assertStringNotContainsString('Zoraida', $body);
        $this->assertStringNotContainsString('Konstantinidis', $body);
        $this->assertArrayNotHasKey('items', $payroll);
    }

    /* ------------------------------------------------------ envelope */

    public function test_blocks_are_always_a_subset_of_the_switches(): void
    {
        // A renderer that has to reconcile two lists is a renderer with a
        // bug waiting in it: every key drawn must be a key that was on.
        $this->actingAsRole('HR Admin');

        $data = $this->getJson('/api/v1/dashboards/hr')->assertOk()->json('data');

        $this->assertSame(['role', 'available', 'blocks', 'generated_at'], array_keys($data));

        foreach (array_keys($data['blocks']) as $block) {
            $this->assertArrayHasKey($block, $data['available']);
            $this->assertTrue($data['available'][$block]);
        }

        // An off switch is a value, not an absent key — otherwise a client
        // cannot tell "no permission" from "nothing happened to load".
        foreach ($data['available'] as $block => $on) {
            $this->assertIsBool($on, "The switch for {$block} is not a boolean.");
        }
    }
}
