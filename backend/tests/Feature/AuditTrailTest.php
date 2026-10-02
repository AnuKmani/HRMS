<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The audit trail: vocabulary, coverage, redaction, gates and filters.
 *
 * The trail is written by model events (see `AuditLogger` and
 * `AppServiceProvider::registerAuditLogging`), not by calls at the end of
 * a controller — so the thing worth proving is that a *write* shows up
 * under the right name, and that nothing sensitive rides along with it.
 *
 * Spec item I lists fifteen sensitive mutations. Fourteen of them are
 * producible today and are asserted below as `module` + `action`; the
 * fifteenth, `credential.reauthentication`, has no producer because this
 * application has no separate re-authentication step — the current
 * password is proven on every password change and on every session
 * revocation, which is the same fact at the same strength. The remaining
 * entry, `attendance.manual_adjustment`, needs a real actor to compare
 * against and so is proved behaviourally rather than against a value
 * object.
 */
class AuditTrailTest extends TestCase
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

    /* ------------------------------------------------------- vocabulary */

    /**
     * Every mutation the spec names that a value object can produce.
     *
     * @return array<string, array{0: Model, 1: string, 2: array<string, mixed>, 3: array<string, mixed>, 4: string}>
     */
    public static function mutations(): array
    {
        return [
            'credential.password_change' => [
                new User, 'updated',
                ['password' => '$2y$12$oldhash'],
                ['password' => '[redacted]'],
                'password_change',
            ],
            'credential.deactivation' => [
                new User, 'updated',
                ['status' => 'active'],
                ['status' => 'inactive'],
                'deactivation',
            ],
            'employee.update' => [
                new Employee, 'updated',
                ['phone' => '+971500000001'],
                ['phone' => '+971500000002'],
                'update',
            ],
            'employee.salary_change' => [
                new Employee, 'updated',
                ['salary' => '10000.00'],
                ['salary' => '14500.00'],
                'salary_change',
            ],
            'employee.status_change' => [
                new Employee, 'updated',
                ['employment_status' => 'active'],
                ['employment_status' => 'suspended'],
                'status_change',
            ],
            'leave.approval' => [
                new LeaveRequest, 'updated',
                ['status' => 'pending'],
                ['status' => 'approved'],
                'approval',
            ],
            'overtime.approval' => [
                new OvertimeRequest, 'updated',
                ['status' => 'pending'],
                ['status' => 'approved'],
                'approval',
            ],
            'expense.approval' => [
                new Expense, 'updated',
                ['status' => 'pending'],
                ['status' => 'approved'],
                'approval',
            ],
            'loan.approval' => [
                new Loan, 'updated',
                ['status' => 'pending'],
                ['status' => 'approved'],
                'approval',
            ],
            'payroll.calculate' => [
                new Payroll, 'created',
                [],
                ['net_salary' => '4200.00'],
                'calculate',
            ],
            'payroll.lock' => [
                new Payroll, 'updated',
                ['status' => 'processed', 'locked_at' => null],
                ['status' => 'locked', 'locked_at' => '2026-10-01 00:00:00'],
                'lock',
            ],
            'asset.assignment' => [
                new AssetAssignment, 'created',
                [],
                ['status' => 'active'],
                'assignment',
            ],
            'asset.returns' => [
                new AssetAssignment, 'updated',
                ['status' => 'active'],
                ['status' => 'returned'],
                'returns',
            ],
        ];
    }

    #[DataProvider('mutations')]
    public function test_the_trail_names_the_mutation_the_spec_calls_it(
        Model $model,
        string $event,
        array $before,
        array $after,
        string $expected,
    ): void {
        $logger = new AuditLogger;

        $this->assertSame(
            $expected,
            $logger->actionFor($model, $event, $before, $after),
        );
    }

    public function test_the_module_is_the_left_half_of_the_same_name(): void
    {
        $expected = [
            User::class => 'credential',
            Employee::class => 'employee',
            Attendance::class => 'attendance',
            LeaveRequest::class => 'leave',
            OvertimeRequest::class => 'overtime',
            Payroll::class => 'payroll',
            Loan::class => 'loan',
            Expense::class => 'expense',
            Asset::class => 'asset',
            AssetAssignment::class => 'asset',
        ];

        $logger = new AuditLogger;

        foreach ($expected as $class => $module) {
            $this->assertSame($module, $logger->moduleFor(new $class), $module.' module is wrong.');
        }

        // `credential` + `password_change` reads back as the spec's dotted
        // name; so does `leave` + `approval`. That identity is why the two
        // are separate columns rather than one concatenated string.
        $this->assertSame(
            'credential.password_change',
            implode('.', ['credential', $logger->actionFor(new User, 'updated', ['password' => 'a'], ['password' => 'b'])]),
        );
    }

    /* ---------------------------------------------------- behavioural */

    public function test_a_password_change_is_recorded_without_the_password(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'Completely1Different',
            'password_confirmation' => 'Completely1Different',
        ])->assertOk();

        $log = AuditLog::query()
            ->where('module', 'credential')
            ->where('action', 'password_change')
            ->first();

        $this->assertNotNull($log, 'A password change must reach the trail.');
        $this->assertSame($user->id, $log->user_id);
        $this->assertNotNull($log->ip_address);
        $this->assertNotNull($log->auditable_id);

        $stored = json_encode([$log->old_values, $log->new_values]);

        $this->assertStringContainsString('[redacted]', (string) $stored);
        $this->assertStringNotContainsString('Completely1Different', (string) $stored);

        $hash = (string) $user->fresh()->password;

        $this->assertStringNotContainsString($hash, (string) $stored);
    }

    public function test_a_salary_change_is_recorded_as_a_salary_change(): void
    {
        $hr = $this->actingAsRole('HR Admin');

        $employee = Employee::factory()->create();

        $before = (string) $employee->salary;

        $employee->update(['salary' => $before === '9999' ? '1234' : '9999']);

        $log = AuditLog::query()
            ->where('module', 'employee')
            ->where('action', 'salary_change')
            ->where('auditable_id', $employee->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($hr->id, $log->user_id);
        $this->assertSame($before, (string) ($log->old_values['salary'] ?? null));
        $this->assertNotSame(
            (string) ($log->old_values['salary'] ?? null),
            (string) ($log->new_values['salary'] ?? null),
        );
    }

    public function test_changing_somebody_s_status_is_recorded_as_a_status_change(): void
    {
        $this->actingAsRole('HR Admin');

        $employee = Employee::factory()->create();
        $employee->update(['employment_status' => 'suspended']);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'employee',
            'action' => 'status_change',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_the_employee_checking_out_is_not_in_their_own_trail(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Employee');

        $employee = Employee::factory()->create(['user_id' => $owner->id]);
        $attendance = Attendance::factory()->create(['employee_id' => $employee->id]);

        // Recording your own day is not a mutation made *to* you: the
        // actor and the subject are the same person, and the geofence and
        // selfie already say what the row says. Auditing it would bury the
        // trail in two entries per employee per day.
        Sanctum::actingAs($owner);

        $attendance->update(['late_minutes' => 3]);

        $this->assertSame(
            0,
            AuditLog::query()->where('module', 'attendance')->count(),
        );
    }

    public function test_somebody_else_changing_a_recorded_day_is_manual_adjustment(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Employee');

        $employee = Employee::factory()->create(['user_id' => $owner->id]);
        $attendance = Attendance::factory()->create(['employee_id' => $employee->id]);

        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');

        Sanctum::actingAs($hr);

        $attendance->update(['status' => Attendance::STATUS_MANUALLY_ADJUSTED]);

        $log = AuditLog::query()
            ->where('module', 'attendance')
            ->where('action', 'manual_adjustment')
            ->first();

        $this->assertNotNull($log, 'A rewritten day must reach the trail.');
        $this->assertSame($hr->id, $log->user_id);
        $this->assertSame($attendance->id, $log->auditable_id);
    }

    /* ------------------------------------------------------------ gates */

    public function test_the_trail_is_gated_by_audit_view(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();

        // Employee holds a dozen permissions and not this one; it is
        // granted to the roles that audit other people rather than to the
        // people the trail records.
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/audit-logs/options')->assertForbidden();

        $hr = User::factory()->create();
        $hr->assignRole('HR Admin');

        Sanctum::actingAs($hr);

        $this->getJson('/api/v1/audit-logs')->assertOk();
        $this->getJson('/api/v1/audit-logs/options')->assertOk();
    }

    /* ---------------------------------------------------------- filters */

    /**
     * @return array{0: User, 1: Employee}
     */
    private function writeSomeTrail(): array
    {
        $hr = $this->actingAsRole('HR Admin');

        $employee = Employee::factory()->create();
        $employee->update(['salary' => '7777']);
        $employee->update(['employment_status' => 'suspended']);
        $employee->update(['first_name' => 'Renamed']);

        return [$hr, $employee];
    }

    public function test_filters_by_module_action_actor_record_and_date(): void
    {
        [$hr, $employee] = $this->writeSomeTrail();

        $this->getJson('/api/v1/audit-logs?module=employee')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 4);

        $this->getJson('/api/v1/audit-logs?module=employee&action=salary_change')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/v1/audit-logs?module=leave')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        $this->getJson("/api/v1/audit-logs?user_id={$hr->id}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 4);

        $other = User::factory()->create();

        $this->getJson("/api/v1/audit-logs?user_id={$other->id}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        // Both spellings: the client sends the short name, the column
        // holds the class.
        $this->getJson('/api/v1/audit-logs?auditable_type='.urlencode('App\Models\Employee'))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 4);
        $this->getJson('/api/v1/audit-logs?record_type='.urlencode('App\Models\Employee'))
            ->assertOk()
            ->assertJsonPath('data.meta.total', 4);
        $this->getJson('/api/v1/audit-logs?record_type=LeaveRequest')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        $today = now()->toDateString();

        $this->getJson("/api/v1/audit-logs?module=employee&from={$today}&to={$today}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 4);

        $tomorrow = now()->addDay()->toDateString();

        $this->getJson("/api/v1/audit-logs?module=employee&from={$tomorrow}")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        unset($employee);
    }

    public function test_an_unknown_filter_value_is_an_empty_page_rather_than_a_422(): void
    {
        $this->writeSomeTrail();

        // A filter list read from the data goes stale the moment a module
        // is added; refusing a bookmark that outlived its usefulness would
        // be a worse answer than showing that nothing sits under it.
        $this->getJson('/api/v1/audit-logs?module=nonsense&action=whatever')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        // A date that does not parse is dropped rather than applied, so
        // the page still answers 200 with everything under the filter.
        $this->getJson('/api/v1/audit-logs?module=employee&from=last-tuesday')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 4);
    }

    public function test_the_trail_paginates_with_the_same_four_numbers_as_every_list(): void
    {
        $this->writeSomeTrail();

        $response = $this->getJson('/api/v1/audit-logs?module=employee&per_page=2')
            ->assertOk();

        $meta = $response->json('data.meta');

        $this->assertSame(
            ['current_page', 'last_page', 'per_page', 'total', 'has_next'],
            array_keys($meta),
        );
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(2, $meta['per_page']);
        $this->assertSame(4, $meta['total']);
        $this->assertSame(2, $meta['last_page']);
        $this->assertTrue($meta['has_next']);
        $this->assertCount(2, $response->json('data.items'));

        $this->getJson('/api/v1/audit-logs?module=employee&per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.has_next', false);
    }

    public function test_the_filter_options_are_read_from_the_data_not_a_constant(): void
    {
        $this->writeSomeTrail();

        $data = $this->getJson('/api/v1/audit-logs/options')
            ->assertOk()
            ->json('data');

        $this->assertContains('employee', $data['modules']);
        $this->assertContains('salary_change', $data['actions']);

        $records = array_column($data['records'], 'type');

        $this->assertContains('App\Models\Employee', $records);
        $this->assertSame('Employee', $data['records'][0]['label'] ?? null);
    }

    /* ---------------------------------------------------------- redaction */

    public function test_a_path_or_a_password_never_reaches_the_trail_in_clear(): void
    {
        $hr = $this->actingAsRole('HR Admin');

        // A private-storage path is not a secret — it grants nothing
        // without the API that serves it — but it is a map of where a
        // passport scan lives, and a trail read by five roles does not
        // need one. The key survives (the file *was* attached), the value
        // does not. A home address, by contrast, is not on the redaction
        // list at all: the rule is a rule, not a mood.
        $employee = Employee::factory()->create();
        $employee->update([
            'photo_path' => 'private/passports/scan-abc123.png',
            'address' => '14 Palm Jumeirah, Dubai',
        ]);

        $log = AuditLog::query()
            ->where('module', 'employee')
            ->where('action', 'update')
            ->where('auditable_id', $employee->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('[redacted]', $log->new_values['photo_path'] ?? null);
        $this->assertSame('14 Palm Jumeirah, Dubai', $log->new_values['address'] ?? null);
        $this->assertStringNotContainsString(
            'scan-abc123',
            (string) json_encode([$log->old_values, $log->new_values]),
        );

        // And the credential column, which is the one that matters: the
        // fact of the change survives, the value never arrives.
        $user = User::factory()->create();

        $user->update(['password' => 'a-brand-new-secret']);

        $credential = AuditLog::query()
            ->where('module', 'credential')
            ->where('action', 'password_change')
            ->where('auditable_id', $user->id)
            ->first();

        $this->assertNotNull($credential);
        $this->assertStringNotContainsString(
            'a-brand-new-secret',
            (string) json_encode([$credential->old_values, $credential->new_values]),
        );
        $this->assertArrayHasKey('password', $credential->new_values);
    }
}
