<?php

namespace Tests\Feature;

use App\Models\ApprovalRecord;
use App\Models\Attendance;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * POST/GET/PUT /api/v1/overtime and its four transitions.
 *
 * Overtime is the leave engine pointed at a different outcome, and the file
 * is arranged around the three things that make it different:
 *
 *  - **it describes a day that already happened**, so the date rule looks
 *    strict next to leave's and the attendance link is resolved by the server
 *    rather than offered by the client;
 *  - **there is no balance to protect**, so what matters instead is
 *    `approved_minutes` — asked for, granted, and never allowed to stand in
 *    for each other;
 *  - **completion writes exactly one flag**, `payroll_eligible`, which is the
 *    only thing a future payroll would read. Nothing re-derives it from
 *    status, so the tests assert the column rather than the label.
 *
 * The chain is OT-STD out of the box — reporting manager, project manager,
 * then anybody holding `overtime.approve` — and one test swaps in a
 * one-step chain through the real configuration endpoint, which is the point
 * of having a configurable workflow rather than a hard-coded one.
 */
class OvertimeTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    private Project $project;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();

        $this->project = Project::factory()->create();
        $this->site = Site::factory()->create(['project_id' => $this->project->id]);
    }

    /* ------------------------------------------------------------- create */

    public function test_a_claim_is_a_draft_and_ignores_every_field_the_client_does_not_own(): void
    {
        [, $employee] = $this->signInAs('Employee');

        // The day's real attendance. The client will try to name it directly
        // and will be ignored; the link has to come from the server's own
        // lookup, or a claim could be hung off somebody else's shift.
        $attendance = Attendance::factory()->create([
            'employee_id' => $employee->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'attendance_date' => '2026-09-27',
        ]);

        $claim = $this->postJson('/api/v1/overtime', [
            'overtime_date' => '2026-09-27',
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'requested_minutes' => 120,
            'reason' => 'Handover ran long.',

            // None of these are the claimant's to state.
            'employee_id' => $employee->id + 999,
            'attendance_id' => $attendance->id + 999,
            'approved_minutes' => 600,
            'payroll_eligible' => true,
            'status' => 'approved',
            'current_approval_step' => 9,
        ])->assertCreated()->json('data');

        $this->assertSame($employee->id, $claim['employee_id']);
        $this->assertSame($attendance->id, $claim['attendance_id'], 'Resolved from the date, not accepted from the wire.');
        $this->assertSame('draft', $claim['status']);
        $this->assertNull($claim['submitted_at']);
        $this->assertNull($claim['approved_minutes']);
        $this->assertNull($claim['current_approval_step']);
        $this->assertFalse($claim['payroll_eligible']);
        $this->assertFalse($claim['is_payroll_eligible']);
        $this->assertSame(120, $claim['requested_minutes']);
        $this->assertSame(2.0, (float) $claim['requested_hours']);
    }

    public function test_overtime_can_only_be_claimed_for_a_day_that_happened(): void
    {
        $this->signInAs('Employee');

        // Overtime is a claim about time already spent. Leave may be planned
        // for next month; tomorrow's overtime is hours nobody has worked.
        $this->postJson('/api/v1/overtime', [
            'overtime_date' => '2026-09-29',
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'requested_minutes' => 60,
            'reason' => 'Tomorrow, roughly.',
        ])->assertUnprocessable()->assertJsonValidationErrors('overtime_date');

        $this->postJson('/api/v1/overtime', [
            'overtime_date' => '2026-09-27',
            'requested_minutes' => 0,
            'reason' => 'Nothing at all.',
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_minutes');

        // No single day holds more than 24 hours; a longer claim is several
        // claims.
        $this->postJson('/api/v1/overtime', [
            'overtime_date' => '2026-09-27',
            'requested_minutes' => 1441,
            'reason' => 'The whole weekend and then some.',
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_minutes');

        $this->postJson('/api/v1/overtime', [
            'overtime_date' => '2026-09-27',
            'requested_minutes' => 60,
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->assertSame(0, OvertimeRequest::query()->count());
    }

    /* --------------------------------------------------------- transitions */

    public function test_the_chain_walks_in_order_and_only_the_current_step_may_sign(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $manager = $this->makeSeat('Project Manager');
        $hr = $this->makeSeat('HR Admin');

        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $id = $this->claim()['id'];

        $this->postJson("/api/v1/overtime/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_approval_step', 1);

        // HR holds the permission the *third* step asks for. That is not an
        // invitation to answer the first one.
        $this->become($hr[0]);
        $this->postJson("/api/v1/overtime/{$id}/approve", ['approved_minutes' => 60])
            ->assertForbidden();

        // And the project manager is still one link further down.
        $this->become($manager[0]);
        $this->postJson("/api/v1/overtime/{$id}/approve")->assertForbidden();

        // The current step — and it may trim the claim while signing, which
        // is what `approved_minutes` on this action means.
        $this->become($supervisor[0]);
        $this->postJson("/api/v1/overtime/{$id}/approve", [
            'approved_minutes' => 60,
            'remarks' => 'Half of that overlaps the shift already paid.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_approval_step', 2)
            ->assertJsonPath('data.approved_minutes', 60)
            ->assertJsonPath('data.payroll_eligible', false);

        $this->become($manager[0]);
        $this->postJson("/api/v1/overtime/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.current_approval_step', 3);

        $this->become($hr[0]);
        $this->postJson("/api/v1/overtime/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.payroll_eligible', true)
            ->assertJsonPath('data.approved_minutes', 60);

        $row = OvertimeRequest::query()->findOrFail($id);
        $this->assertSame(60, (int) $row->approved_minutes, 'The trim survived the two steps that followed it.');
        $this->assertSame(120, (int) $row->requested_minutes, 'What was asked for is still on the row.');
        $this->assertTrue($row->isPayrollEligible());
        $this->assertNotNull($row->approved_at);
        $this->assertNull($row->current_approval_step);
    }

    public function test_the_chain_is_configurable_so_overtime_does_not_have_to_walk_three_links(): void
    {
        $hr = $this->makeSeat('HR Admin')[0];

        // Through the real configuration endpoint, not by rewriting rows:
        // HR holds `approvals.manage`, and a new default clears the old one.
        $this->become($hr);
        $this->postJson('/api/v1/approval-workflows', [
            'name' => 'Fast overtime chain',
            'code' => 'OT-FAST',
            'subject_type' => 'overtime',
            'is_default' => true,
            'steps' => [
                ['sequence' => 1, 'approver_type' => 'role', 'approver_role' => 'HR Admin'],
            ],
        ])->assertCreated();

        $this->signInAs('Employee');
        $id = $this->claim()['id'];

        $this->postJson("/api/v1/overtime/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.current_approval_step', 1);

        $this->become($hr);
        $this->postJson("/api/v1/overtime/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.payroll_eligible', true)
            ->assertJsonPath('data.approved_minutes', 120, 'Nobody trimmed it, so the grant is the claim.');
    }

    public function test_nobody_approves_their_own_claim_even_when_the_chain_points_at_them(): void
    {
        [$user, $employee] = $this->signInAs('Employee');

        // The one configuration that would let this happen: the requester is
        // their own reporting manager, so step one resolves to them.
        $employee->update(['reporting_manager_id' => $employee->id]);

        $id = $this->claim()['id'];
        $this->postJson("/api/v1/overtime/{$id}/submit")->assertOk();

        $this->postJson("/api/v1/overtime/{$id}/approve")->assertForbidden();
        $this->postJson("/api/v1/overtime/{$id}/reject")->assertForbidden();

        $row = OvertimeRequest::query()->findOrFail($id);
        $this->assertSame('pending', $row->status, 'The refusal blocked the act, not the request.');
        $this->assertFalse($row->isPayrollEligible());
        $this->assertNull($row->approved_minutes);
    }

    public function test_a_refusal_is_recorded_and_leaves_nothing_payable(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $id = $this->claim()['id'];
        $this->postJson("/api/v1/overtime/{$id}/submit")->assertOk();

        $this->become($supervisor[0]);
        $this->postJson("/api/v1/overtime/{$id}/reject", [
            'remarks' => 'Covered by the shift already paid.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.payroll_eligible', false);

        $row = OvertimeRequest::query()->findOrFail($id);
        $this->assertSame($supervisor[0]->id, $row->rejected_by);
        $this->assertNotNull($row->rejected_at);
        $this->assertSame('Covered by the shift already paid.', $row->remarks);
        $this->assertNull($row->approved_minutes, 'A refusal has no amount to remember.');

        // The chain is closed rather than left waiting on a second opinion
        // that can no longer change the outcome.
        $this->postJson("/api/v1/overtime/{$id}/approve")->assertForbidden();
        $this->assertSame(0, ApprovalRecord::query()
            ->where('subject_type', 'overtime_request')
            ->where('subject_id', $id)
            ->where('status', 'pending')
            ->count());
    }

    public function test_the_approved_amount_is_capped_by_what_was_actually_asked(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $id = $this->claim(120)['id'];
        $this->postJson("/api/v1/overtime/{$id}/submit")->assertOk();

        $this->become($supervisor[0]);

        $this->postJson("/api/v1/overtime/{$id}/approve", ['approved_minutes' => 121])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approved_minutes');

        $this->postJson("/api/v1/overtime/{$id}/approve", ['approved_minutes' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approved_minutes');

        // Still exactly where it was: neither refusal wrote a number.
        $row = OvertimeRequest::query()->findOrFail($id);
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->approved_minutes);

        $this->postJson("/api/v1/overtime/{$id}/approve", ['approved_minutes' => 90])
            ->assertOk()
            ->assertJsonPath('data.approved_minutes', 90);
    }

    public function test_a_draft_can_be_edited_and_anything_settled_cannot(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $id = $this->claim(60)['id'];

        $this->putJson("/api/v1/overtime/{$id}", ['requested_minutes' => 75])
            ->assertOk()
            ->assertJsonPath('data.requested_minutes', 75)
            ->assertJsonPath('data.status', 'draft');

        $this->postJson("/api/v1/overtime/{$id}/submit")->assertOk();

        $this->putJson("/api/v1/overtime/{$id}", ['requested_minutes' => 90])
            ->assertStatus(409);

        $this->postJson("/api/v1/overtime/{$id}/submit")->assertStatus(409);
    }

    public function test_a_claim_can_be_cancelled_but_a_settled_one_cannot(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $cancelled = $this->claim(60)['id'];
        $this->postJson("/api/v1/overtime/{$cancelled}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull(OvertimeRequest::query()->findOrFail($cancelled)->cancelled_at);
        $this->postJson("/api/v1/overtime/{$cancelled}/cancel")->assertStatus(409);

        // A pending claim can still be withdrawn — and withdrawing it closes
        // the chain rather than leaving approvers holding a dead request.
        $pending = $this->claim(90)['id'];
        $this->postJson("/api/v1/overtime/{$pending}/submit")->assertOk();
        $this->postJson("/api/v1/overtime/{$pending}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, ApprovalRecord::query()
            ->where('subject_type', 'overtime_request')
            ->where('subject_id', $pending)
            ->where('status', 'pending')
            ->count());
    }

    /* --------------------------------------------------------- authorization */

    public function test_an_account_without_the_approve_permission_cannot_reach_the_approval_actions(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $id = $this->claim()['id'];
        $this->postJson("/api/v1/overtime/{$id}/submit")->assertOk();

        // Another employee holds `overtime.create` — enough to ask, not to
        // answer, and the route says so before any policy is consulted.
        $this->become($this->makeSeat('Employee')[0]);
        $this->postJson("/api/v1/overtime/{$id}/approve")->assertForbidden();
        $this->postJson("/api/v1/overtime/{$id}/reject")->assertForbidden();

        $this->assertSame('pending', OvertimeRequest::query()->findOrFail($id)->status);
    }

    public function test_an_employee_reads_only_their_own_claims(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');

        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $mine = $this->claim()['id'];

        $theirs = $this->makeSeat('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $this->become($theirs[0]);
        $theirsId = $this->claim(90)['id'];

        $items = $this->getJson('/api/v1/overtime')->assertOk()->json('data.items');
        $this->assertSame(
            [$theirs[1]->id],
            array_column($items, 'employee_id'),
            'Overtime claimed by a colleague is not published by the permission that reads your own.',
        );

        $this->getJson("/api/v1/overtime/{$mine}")->assertForbidden();
        $this->getJson("/api/v1/overtime/{$theirsId}")->assertOk();
    }

    /* ------------------------------------------------------------ payroll */

    public function test_only_a_completed_claim_is_payroll_eligible_and_the_filter_agrees(): void
    {
        $hr = $this->makeSeat('HR Admin');

        // A one-step chain, configured the way an administrator would, so the
        // test spends its lines on the flag rather than on three approvers.
        $this->become($hr[0]);
        $this->postJson('/api/v1/approval-workflows', [
            'name' => 'Fast overtime chain',
            'code' => 'OT-FAST',
            'subject_type' => 'overtime',
            'is_default' => true,
            'steps' => [
                ['sequence' => 1, 'approver_type' => 'role', 'approver_role' => 'HR Admin'],
            ],
        ])->assertCreated();

        $this->signInAs('Employee');

        // Waiting on somebody, refused, withdrawn and — for now — waiting too.
        $pending = $this->claim(60, '2026-09-27')['id'];
        $refused = $this->claim(75, '2026-09-26')['id'];
        $withdrawn = $this->claim(45, '2026-09-25')['id'];
        $settled = $this->claim(120, '2026-09-24')['id'];

        foreach ([$pending, $refused, $withdrawn, $settled] as $id) {
            $this->postJson("/api/v1/overtime/{$id}/submit")->assertOk();
        }

        $this->postJson("/api/v1/overtime/{$withdrawn}/cancel")->assertOk();

        $this->become($hr[0]);
        $this->postJson("/api/v1/overtime/{$refused}/reject")->assertOk();

        $this->assertSame(
            [],
            $this->payrollEligible(),
            'Nothing that did not complete may present itself to payroll.',
        );

        // One approval later, and only one row.
        $this->postJson("/api/v1/overtime/{$settled}/approve")->assertOk();

        $rows = $this->payrollEligible();
        $this->assertCount(1, $rows);
        $this->assertSame($settled, $rows[0]['id']);
        $this->assertTrue($rows[0]['payroll_eligible']);
        $this->assertSame(120, $rows[0]['approved_minutes']);
    }

    public function test_a_cancelled_or_refused_claim_is_never_payroll_eligible(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $refused = $this->claim(90, '2026-09-27')['id'];
        $this->postJson("/api/v1/overtime/{$refused}/submit")->assertOk();
        $this->become($supervisor[0]);
        $this->postJson("/api/v1/overtime/{$refused}/reject")->assertOk();

        $cancelled = $this->claim(60, '2026-09-26')['id'];
        $this->postJson("/api/v1/overtime/{$cancelled}/submit")->assertOk();
        $this->postJson("/api/v1/overtime/{$cancelled}/cancel")->assertOk();

        foreach ([$refused, $cancelled] as $id) {
            $row = OvertimeRequest::query()->findOrFail($id);
            $this->assertFalse(
                $row->isPayrollEligible(),
                "A {$row->status} claim presented itself as payable.",
            );
            $this->assertNull($row->approved_minutes);
        }
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Create a draft claim as whoever is signed in.
     */
    private function claim(int $minutes = 120, string $date = '2026-09-27'): array
    {
        return $this->postJson('/api/v1/overtime', [
            'overtime_date' => $date,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
            'requested_minutes' => $minutes,
            'reason' => 'Handover ran long.',
        ])->assertCreated()->json('data');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payrollEligible(): array
    {
        return $this->getJson('/api/v1/overtime?payroll_eligible=true')
            ->assertOk()
            ->json('data.items');
    }
}
