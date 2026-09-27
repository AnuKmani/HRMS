<?php

namespace Tests\Feature;

use App\Models\ApprovalRecord;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * POST|PUT /api/v1/leave and its four transitions.
 *
 * What every test here is really about: a leave request is a claim about
 * somebody's time that ends up in a payroll input, so the three things that
 * could corrupt it — a number the client chose, a day that was not working,
 * and a signature from the wrong person — are each refused in a different
 * place and each proved here.
 *
 * The clock is pinned to Monday 2026-09-28, so "five days" means the week
 * this test is looking at rather than whenever CI ran it.
 */
class LeaveRequestTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
    }

    /* ------------------------------------------------- days are derived */

    public function test_the_day_count_is_derived_and_not_a_claim_the_client_makes(): void
    {
        $this->signInAs('Employee');

        // Both of these are fields a naive form would happily send. Neither is
        // read: `requested_days` is calculated from the range and the holiday
        // calendar, and `status` is the service's to move.
        $leave = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02', [
            'requested_days' => 99,
            'status' => 'approved',
        ]);

        $this->assertRequestedDays($leave, 5.0);
        $this->assertSame('draft', $leave['status']);
        $this->assertNull($leave['submitted_at']);
    }

    public function test_weekends_are_not_leave_days(): void
    {
        $this->signInAs('Employee');

        // Monday to the following Sunday: seven calendar days, five of them
        // working days.
        $leave = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-04');

        $this->assertRequestedDays($leave, 5.0);
    }

    public function test_a_public_holiday_inside_the_range_is_not_counted(): void
    {
        $this->signInAs('Employee');

        Holiday::query()->create([
            'name' => 'Founders Day',
            'date' => '2026-09-30',
            'type' => Holiday::TYPE_PUBLIC,
        ]);

        $leave = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02');

        $this->assertRequestedDays($leave, 4.0, 'Wednesday is a public holiday and is not leave.');
    }

    public function test_a_site_holiday_excludes_that_site_only(): void
    {
        $project = Project::factory()->create();
        $atTheShutdown = Site::factory()->create(['project_id' => $project->id]);
        $elsewhere = Site::factory()->create(['project_id' => $project->id]);

        Holiday::query()->create([
            'name' => 'Site shutdown',
            'date' => '2026-10-01',
            'type' => Holiday::TYPE_SITE,
            'site_id' => $atTheShutdown->id,
        ]);

        [, $onSite] = $this->signInAs('Employee', ['primary_site_id' => $atTheShutdown->id]);
        $onSiteLeave = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02', [
            'site_id' => $atTheShutdown->id,
        ]);
        $this->assertRequestedDays($onSiteLeave, 4.0);

        // The identical range, one site over. Same calendar, same week, same
        // shutdown day — only `site_id` differs, and it must not be taken off.
        $otherUser = $this->makeSeat('Employee', ['primary_site_id' => $elsewhere->id])[0];
        $this->become($otherUser);
        $otherLeave = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02', [
            'site_id' => $elsewhere->id,
        ]);
        $this->assertRequestedDays($otherLeave, 5.0, 'A shutdown at another site is a working day here.');

        // The first request's own date range must not have been disturbed by
        // anything the second caller did.
        $this->assertSame(4.0, (float) LeaveRequest::query()->find($onSiteLeave['id'])->requested_days);
    }

    public function test_an_inactive_holiday_still_counts_as_working_time(): void
    {
        $this->signInAs('Employee');

        Holiday::query()->create([
            'name' => 'Withdrawn day',
            'date' => '2026-09-30',
            'type' => Holiday::TYPE_PUBLIC,
            'status' => Holiday::STATUS_INACTIVE,
        ]);

        $leave = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02');

        $this->assertRequestedDays($leave, 5.0, 'An inactive holiday is a working day.');
    }

    /* ----------------------------------------------------- balance rules */

    public function test_submitting_reserves_the_days_and_rejecting_gives_them_back(): void
    {
        $leaveType = $this->leaveTypeId('AL');
        $supervisor = $this->makeSeat('Site Supervisor');

        [, $employee] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $draft = $this->draftLeave($leaveType, '2026-09-28', '2026-10-02');

        // Nothing is reserved while the request is only a draft: the days are
        // still theirs to book elsewhere.
        $this->assertNull(LeaveBalance::query()->where('employee_id', $employee->id)->first());

        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType)
            ->where('year', 2026)
            ->firstOrFail();

        $this->assertSame(5.0, (float) $balance->pending);
        $this->assertSame(0.0, (float) $balance->used);
        $this->assertSame(7.0, $balance->remaining());

        // Rejection returns the reservation rather than leaving a hole in the
        // pot — nothing was spent, only proposed.
        $this->become($supervisor[0]);
        $this->postJson("/api/v1/leave/{$draft['id']}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $balance->refresh();
        $this->assertSame(0.0, (float) $balance->pending);
        $this->assertSame(0.0, (float) $balance->used);
        $this->assertSame(12.0, $balance->remaining());
    }

    public function test_a_request_beyond_the_entitlement_is_refused_on_submit(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        [, $employee] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        // 13 working days against Annual Leave's 12 — inside the type's
        // 15-day per-request cap, so the cap is not what refuses it.
        $draft = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-14');
        $this->assertRequestedDays($draft, 13.0);

        $this->postJson("/api/v1/leave/{$draft['id']}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leave_type_id');

        // The transaction rolled back: still a draft, no balance row, no
        // half-open approval chain.
        $leave = LeaveRequest::query()->findOrFail($draft['id']);
        $this->assertSame('draft', $leave->status);
        $this->assertNull($leave->submitted_at);
        $this->assertFalse(LeaveBalance::query()->where('employee_id', $employee->id)->exists());
        $this->assertSame(0, ApprovalRecord::query()->where('subject_id', $leave->id)->count());
    }

    public function test_unpaid_leave_is_never_refused_for_balance_reasons(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        [, $employee] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        // Unpaid Leave carries a zero entitlement and `allow_negative_balance`
        // — it is not a pot, so it is never asked whether one has run out.
        $draft = $this->draftLeave($this->leaveTypeId('UL'), '2026-09-28', '2026-10-02');
        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $this->leaveTypeId('UL'))
            ->firstOrFail();

        $this->assertSame(0.0, (float) $balance->pending, 'An unpaid type has nothing to reserve.');
    }

    public function test_overlapping_ranges_are_refused_before_a_second_claim_can_exist(): void
    {
        $this->signInAs('Employee');

        $first = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02');
        $this->postJson("/api/v1/leave/{$first['id']}/submit")->assertOk();

        // Refused at creation, not at submit: a draft that merely overlapped
        // would be a second claim on the same days the moment somebody
        // pressed submit, and the balance check would never have seen it.
        $this->postJson('/api/v1/leave', [
            'leave_type_id' => $this->leaveTypeId('AL'),
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
            'reason' => 'Trying to double book.',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');

        // Touching the boundary is fine — the ranges share no day.
        $this->draftLeave($this->leaveTypeId('AL'), '2026-10-05', '2026-10-09');
    }

    /* -------------------------------------------------------- transitions */

    public function test_the_chain_walks_in_order_and_completes_at_the_last_step(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $manager = $this->makeSeat('Project Manager');
        $hr = $this->makeSeat('HR Admin');
        [, $employee] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $leaveType = $this->leaveTypeId('AL');
        $draft = $this->draftLeave($leaveType, '2026-09-28', '2026-10-02');

        // Step 1 opens on the requester's own line manager, resolved at
        // submit time rather than re-read on every approval.
        $this->postJson("/api/v1/leave/{$draft['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_approval_step', 1);

        $chain = $this->getJson("/api/v1/leave/{$draft['id']}")->assertOk()->json('data.approval_chain');
        $this->assertCount(3, $chain);
        $this->assertSame(
            ['reporting_manager', 'role', 'role'],
            array_column($chain, 'approver_type'),
        );

        // Somebody who is NOT the current step may not act — the coarse
        // permission is held, and it still fails.
        $this->become($hr[0]);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")->assertForbidden();

        $this->become($supervisor[0]);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.current_approval_step', 2);

        $this->become($manager[0]);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.current_approval_step', 3);

        $this->become($hr[0]);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.current_approval_step', null);

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType)
            ->firstOrFail();
        $this->assertSame(0.0, (float) $balance->pending);
        $this->assertSame(5.0, (float) $balance->used);

        $chain = $this->getJson("/api/v1/leave/{$draft['id']}")->assertOk()->json('data.approval_chain');
        $this->assertSame(['approved', 'approved', 'approved'], array_column($chain, 'status'));
    }

    public function test_a_workflow_can_be_configured_and_changes_who_approves(): void
    {
        // Point Annual Leave at the one-step chain instead of the default
        // three-step one. Nothing in the services knows about either: the
        // difference is a row.
        LeaveType::query()
            ->where('code', 'AL')
            ->update(['approval_workflow_id' => $this->workflowId('LEAVE-FAST')]);

        $hr = $this->makeSeat('HR Admin');
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $draft = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02');

        $this->postJson("/api/v1/leave/{$draft['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.current_approval_step', 1);

        $chain = $this->getJson("/api/v1/leave/{$draft['id']}")->assertOk()->json('data.approval_chain');
        $this->assertCount(1, $chain, 'The configured chain, not the default, is what materialises.');
        $this->assertSame('HR Admin', $chain[0]['approver_role']);

        // The line manager is no longer in the way.
        $this->become($supervisor[0]);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")->assertForbidden();

        $this->become($hr[0]);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_nobody_approves_their_own_request_even_when_the_chain_points_at_them(): void
    {
        // An HR Admin whose leave type runs straight to HR. By every other
        // rule they are exactly the right approver — and it still fails,
        // because "who may sign off" is checked before "do they hold the
        // role".
        LeaveType::query()
            ->where('code', 'AL')
            ->update(['approval_workflow_id' => $this->workflowId('LEAVE-FAST')]);

        $this->signInAs('HR Admin');

        $draft = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02');
        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();

        $this->postJson("/api/v1/leave/{$draft['id']}/approve")->assertForbidden();

        $leave = LeaveRequest::query()->findOrFail($draft['id']);
        $this->assertSame('pending', $leave->status, 'The refusal changed nothing.');
        $this->assertNull($leave->approved_at);
    }

    public function test_an_account_without_the_approve_permission_cannot_reach_the_endpoint(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $colleague = $this->makeSeat('Employee');
        $this->become($colleague[0]);

        $draft = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02');
        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();

        // `permission:leave.approve` on the route: the door closes before the
        // policy is even consulted, so a request the caller owns is still a
        // refusal.
        $this->become($colleague[0]);
        $this->postJson("/api/v1/leave/{$draft['id']}/approve")->assertForbidden();
    }

    public function test_a_pending_request_can_be_cancelled_and_the_reservation_released(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        [, $employee] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $leaveType = $this->leaveTypeId('AL');
        $draft = $this->draftLeave($leaveType, '2026-09-28', '2026-10-02');
        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();

        $this->postJson("/api/v1/leave/{$draft['id']}/cancel", ['remarks' => 'Plans changed.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType)
            ->firstOrFail();
        $this->assertSame(0.0, (float) $balance->pending);

        // The chain is closed rather than left holding an open step.
        $this->assertSame(
            ['skipped', 'skipped', 'skipped'],
            ApprovalRecord::query()
                ->where('subject_id', $draft['id'])
                ->orderBy('sequence')
                ->pluck('status')
                ->all(),
        );
    }

    public function test_a_submitted_request_can_no_longer_be_edited(): void
    {
        $this->signInAs('Employee');

        $draft = $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02');
        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertOk();

        $this->putJson("/api/v1/leave/{$draft['id']}", ['reason' => 'Changed my mind'])
            ->assertStatus(409);

        $this->postJson("/api/v1/leave/{$draft['id']}/submit")->assertStatus(409);
    }

    public function test_an_employee_only_reads_their_own_requests(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');

        [, $mine] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $this->draftLeave($this->leaveTypeId('AL'), '2026-09-28', '2026-10-02');

        $theirs = $this->makeSeat('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $this->become($theirs[0]);
        $this->draftLeave($this->leaveTypeId('AL'), '2026-10-05', '2026-10-09');

        // This account's own history is one row, and it is theirs alone.
        $items = $this->getJson('/api/v1/leave')->assertOk()->json('data.items');
        $this->assertSame(
            [$theirs[1]->id],
            array_column($items, 'employee_id'),
            'A colleague\'s absence is not published by the same permission that reads your own.',
        );

        // The narrower of the two endpoints agrees with the wider one — a
        // list that hid the row while `show` handed it over would only have
        // made the easier path the wrong one.
        $mineId = LeaveRequest::query()->where('employee_id', $mine->id)->value('id');
        $this->getJson("/api/v1/leave/{$mineId}")->assertForbidden();
    }

    /* ------------------------------------------------------ configuration */

    public function test_leave_types_are_rows_and_not_rules_in_the_services(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        [$employeeUser] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $annual = $this->leaveTypeId('AL');

        // Four working days: inside Annual Leave's seeded 15-day cap.
        $this->draftLeave($annual, '2026-10-05', '2026-10-09');

        // Reconfiguring a leave type is HR's act, not the requester's.
        $this->putJson('/api/v1/leave-types/'.$annual, [
            'maximum_days_per_request' => 2,
        ])->assertForbidden();

        $hr = $this->makeSeat('HR Admin')[0];
        $this->become($hr);
        $this->putJson('/api/v1/leave-types/'.$annual, [
            'maximum_days_per_request' => 2,
        ])->assertOk()->assertJsonPath('data.maximum_days_per_request', 2);

        // Same range, same services, same account that used to succeed — only
        // the row changed, and so did the answer. No rule was edited anywhere.
        // The second range is the following week so that what refuses it is
        // the cap and not the overlap check.
        $this->become($employeeUser);
        $this->postJson('/api/v1/leave', [
            'leave_type_id' => $annual,
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-14',
            'reason' => 'Personal time off.',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_the_approval_workflow_is_configurable_through_its_own_endpoint(): void
    {
        // An ordinary account holds neither half of the configuration module.
        $this->signInAs('Employee');
        $this->getJson('/api/v1/approval-workflows')->assertForbidden();
        $this->postJson('/api/v1/approval-workflows', [])->assertForbidden();

        $this->become($this->makeSeat('HR Admin')[0]);

        $created = $this->postJson('/api/v1/approval-workflows', [
            'name' => 'Emergency chain',
            'code' => 'LEAVE-URGENT',
            'subject_type' => 'leave',
            'steps' => [
                ['sequence' => 2, 'approver_type' => 'permission', 'approver_permission' => 'leave.approve'],
                ['sequence' => 1, 'approver_type' => 'reporting_manager'],
            ],
        ])->assertCreated()->json('data');

        $this->assertSame(
            [1, 2],
            array_column($created['steps'], 'sequence'),
            'The sequence in the payload decides the order, not the order the array happened to be in.',
        );

        // A hole in the sequence would make the runtime invent a step number,
        // and a chain with a gap cannot be walked.
        $this->postJson('/api/v1/approval-workflows', [
            'name' => 'Broken chain',
            'code' => 'LEAVE-BROKEN',
            'subject_type' => 'leave',
            'steps' => [
                ['sequence' => 1, 'approver_type' => 'reporting_manager'],
                ['sequence' => 3, 'approver_type' => 'reporting_manager'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('steps');
    }

    /* ---------------------------------------------------------- balances */

    public function test_an_employee_reads_and_only_reads_their_own_balance_summary(): void
    {
        $mine = $this->signInAs('Employee')[1];
        $colleague = $this->makeSeat('Employee')[1];

        // Materialised on first read: a balance with no row would show "0 of
        // 12" and contradict the leave type it came from.
        $items = $this->getJson(
            '/api/v1/leave-balances?employee_id='.$mine->id.'&year=2026',
        )->assertOk()->json('data.items');

        $this->assertCount(LeaveType::query()->where('status', 'active')->count(), $items);
        $annual = collect($items)->firstWhere('leave_type_id', $this->leaveTypeId('AL'));
        $this->assertSame(12, $annual['entitlement']);
        $this->assertSame(12.0, (float) $annual['remaining']);

        // A colleague's year is neither returned nor invented on their behalf.
        $this->getJson(
            '/api/v1/leave-balances?employee_id='.$colleague->id.'&year=2026',
        )->assertNotFound();
        $this->assertFalse(
            LeaveBalance::query()->where('employee_id', $colleague->id)->exists(),
            'A refused summary must not have written a row while refusing.',
        );

        $items = $this->getJson('/api/v1/leave-balances?year=2026')->assertOk()->json('data.items');
        $this->assertSame(
            [$mine->id],
            array_unique(array_column($items, 'employee_id')),
        );
    }

    public function test_only_hr_can_correct_a_balance_by_hand(): void
    {
        $employee = $this->signInAs('Employee')[1];

        $this->getJson('/api/v1/leave-balances?employee_id='.$employee->id.'&year=2026')
            ->assertOk();

        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $this->leaveTypeId('AL'))
            ->firstOrFail();

        $this->putJson("/api/v1/leave-balances/{$balance->id}", [
            'adjustment' => 3,
        ])->assertForbidden();

        $hr = $this->makeSeat('HR Admin')[0];
        $this->become($hr);

        $this->putJson("/api/v1/leave-balances/{$balance->id}", [
            'entitlement' => 12,
            'carry_forward' => 4,
            'adjustment' => 3,
        ])->assertOk();

        // 12 + 4 + 3 - 0 - 0, from the model's single implementation of the
        // formula rather than a second copy in the assertion.
        $this->assertSame(19.0, $balance->refresh()->remaining());

        // `used` is derived from requests and is not a field this endpoint
        // accepts at all — the ledger and the requests cannot be told apart
        // otherwise.
        $this->putJson("/api/v1/leave-balances/{$balance->id}", ['used' => 99])
            ->assertOk();
        $this->assertSame(0.0, (float) $balance->refresh()->used);
    }

    /* ----------------------------------------------------------- holidays */

    public function test_the_holiday_calendar_is_read_by_everybody_and_written_by_hr_only(): void
    {
        $this->signInAs('Employee');

        $this->postJson('/api/v1/holidays', [
            'name' => 'Not mine to declare',
            'date' => '2026-11-02',
            'type' => 'public',
        ])->assertForbidden();

        $hr = $this->makeSeat('HR Admin')[0];
        $this->become($hr);

        $created = $this->postJson('/api/v1/holidays', [
            'name' => 'Company Day',
            'date' => '2026-11-02',
            'type' => 'company',
        ])->assertCreated()->json('data');

        // Same day, same scope: refused. `Rule::unique` could not express
        // this because company days carry `site_id IS NULL`.
        $this->postJson('/api/v1/holidays', [
            'name' => 'Company Day Again',
            'date' => '2026-11-02',
            'type' => 'company',
        ])->assertUnprocessable();

        // A public day and a company day on the same date are different days
        // in different scopes, and are both allowed.
        $this->postJson('/api/v1/holidays', [
            'name' => 'Board Away Day',
            'date' => '2026-11-02',
            'type' => 'public',
        ])->assertCreated();

        $this->getJson('/api/v1/holidays/'.$created['id'])->assertOk();
    }

    public function test_a_site_holiday_needs_a_site(): void
    {
        $this->signInAs('HR Admin');

        $this->postJson('/api/v1/holidays', [
            'name' => 'Nowhere in particular',
            'date' => '2026-11-03',
            'type' => 'site',
        ])->assertUnprocessable()->assertJsonValidationErrors('site_id');
    }
}
