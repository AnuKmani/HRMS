<?php

namespace Tests\Feature\Concerns;

use App\Models\ApprovalWorkflow;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

/**
 * The Phase 6 furniture every leave / holiday / timesheet / overtime test
 * needs, so each test file can spend its lines on the rule it is proving
 * rather than on seeding.
 *
 * Six seeders, always in this order — permissions need roles, workflows need
 * permissions to be meaningful, and the leave types read a setting that only
 * exists once SettingSeeder has run.
 *
 * Dates: every test in this phase pins the clock to Monday 2026-09-28, which
 * makes "five working days" and "three weeks" questions with one answer
 * instead of whatever day the suite happens to run on.
 */
trait BuildsLeaveStack
{
    /**
     * The fixed Monday the Phase 6 tests reason from.
     */
    private const ANCHOR = '2026-09-28';

    protected function seedLeaveStack(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        $this->seed(ApprovalWorkflowSeeder::class);

        $this->travelTo(Carbon::parse(self::ANCHOR.' 09:00:00'));
    }

    /**
     * An account with its own employee row — created, but NOT signed in.
     *
     * Separate from signInAs() so a test can build a supervisor, a project
     * manager and a requester before deciding which one it is currently
     * talking as.
     *
     * @param  array<string, mixed>  $employee
     * @return array{0: User, 1: Employee}
     */
    protected function makeSeat(string $role, array $employee = []): array
    {
        $model = Employee::factory()->create($employee);

        $user = User::factory()->create();
        $user->assignRole($role);
        $model->update(['user_id' => $user->id]);

        return [$user, $model];
    }

    /**
     * Create an account and become it.
     *
     * @param  array<string, mixed>  $employee
     * @return array{0: User, 1: Employee}
     */
    protected function signInAs(string $role, array $employee = []): array
    {
        [$user, $model] = $this->makeSeat($role, $employee);

        $this->become($user);

        return [$user, $model];
    }

    /**
     * Swap the caller.
     *
     * Deliberately not called `actingAs()`: Laravel's own
     * `InteractsWithAuthentication::actingAs()` already owns that name on
     * TestCase, and a trait method cannot lower its visibility.
     *
     * Guards are forgotten first for the reason TestCase::withToken() gives:
     * the same application instance serves every request in a test, so the
     * memoised Sanctum guard would keep authenticating the first caller for
     * the rest of the file.
     */
    protected function become(User $user): void
    {
        Auth::forgetGuards();

        Sanctum::actingAs($user);
    }

    protected function leaveTypeId(string $code): int
    {
        return (int) LeaveType::query()->where('code', $code)->value('id');
    }

    protected function workflowId(string $code): int
    {
        return (int) ApprovalWorkflow::query()->where('code', $code)->value('id');
    }

    /**
     * Create a draft leave request as whoever is currently signed in.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed> the `data` half of the created envelope
     */
    protected function draftLeave(int $leaveTypeId, string $start, string $end, array $overrides = []): array
    {
        return $this->postJson('/api/v1/leave', array_merge([
            'leave_type_id' => $leaveTypeId,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Personal time off.',
        ], $overrides))->assertCreated()->json('data');
    }

    /**
     * Working days in a range, for a test to assert against a number the
     * calculator produced rather than against its own arithmetic.
     */
    protected function assertRequestedDays(array $leave, float $expected, string $message = ''): void
    {
        $this->assertSame(
            $expected,
            (float) $leave['requested_days'],
            $message ?: sprintf(
                'Expected %s working days between %s and %s.',
                $expected,
                $leave['start_date'],
                $leave['end_date'],
            ),
        );
    }
}
