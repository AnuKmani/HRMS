<?php

namespace App\Services\Overtime;

use App\Events\OvertimeDecided;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to `overtime_requests`, in one order, through one path.
 *
 * Overtime shares its approval chain with leave — same engine, same records,
 * same "only the current step may act" and "nobody approves their own" rules
 * — and differs only in what completion *means*:
 *
 *   leave, approved  -> the days leave the paid balance
 *   OT, approved     -> `payroll_eligible` becomes true and
 *                       `approved_minutes` is fixed
 *
 * Both are decided here rather than in the workflow engine, because the
 * engine's job is to know who signed off, not what a signature costs. That
 * split is why ApprovalWorkflowService returns `advanced` / `completed`
 * instead of finalising the subject itself.
 *
 * The two minute columns are kept apart on purpose. A supervisor may grant 60
 * of the 120 minutes claimed; recording one number would either lose what was
 * originally asked for or overstate what was granted.
 */
final class OvertimeService
{
    public function __construct(private readonly ApprovalWorkflowService $approvals) {}

    /* ------------------------------------------------------------ writing */

    /**
     * @param  array<string, mixed>  $data  validated by StoreOvertimeRequest
     */
    public function create(User $user, array $data): OvertimeRequest
    {
        $employee = $this->employeeFor($user);

        return DB::transaction(function () use ($employee, $data) {
            $overtime = new OvertimeRequest([
                'employee_id' => $employee->id,
                'overtime_date' => $data['overtime_date'],
                'project_id' => $data['project_id'] ?? $employee->primary_project_id,
                'site_id' => $data['site_id'] ?? $employee->primary_site_id,
                'requested_minutes' => (int) $data['requested_minutes'],
                'reason' => trim((string) $data['reason']),
                'status' => OvertimeRequest::STATUS_DRAFT,
            ]);

            // Resolved from the date rather than accepted: a link to somebody
            // else's attendance would attach this claim to a day that is not
            // theirs, and there is nothing useful the client could add here.
            $overtime->attendance_id = $this->attendanceIdFor(
                $employee,
                $overtime->overtime_date->toDateString(),
            );

            $overtime->save();

            return $overtime;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, OvertimeRequest $overtime, array $data): OvertimeRequest
    {
        if (! $overtime->isDraft()) {
            abort(409, 'Only a draft can be edited. Cancel it and start again.');
        }

        return DB::transaction(function () use ($overtime, $data) {
            $overtime->fill([
                'overtime_date' => $data['overtime_date'] ?? $overtime->overtime_date,
                'project_id' => $data['project_id'] ?? $overtime->project_id,
                'site_id' => $data['site_id'] ?? $overtime->site_id,
                'requested_minutes' => (int) ($data['requested_minutes'] ?? $overtime->requested_minutes),
                'reason' => trim((string) ($data['reason'] ?? $overtime->reason)),
            ]);

            $overtime->loadMissing('employee');
            $overtime->attendance_id = $this->attendanceIdFor(
                $overtime->employee,
                $overtime->overtime_date->toDateString(),
            );

            $overtime->save();

            return $overtime;
        });
    }

    public function submit(User $user, OvertimeRequest $overtime): OvertimeRequest
    {
        if (! $overtime->isDraft()) {
            abort(409, 'This request has already been submitted.');
        }

        if ($overtime->requested_minutes <= 0) {
            throw ValidationException::withMessages([
                'requested_minutes' => 'Overtime must be more than zero minutes.',
            ]);
        }

        return DB::transaction(function () use ($overtime) {
            $overtime->submitted_at = now();
            $overtime->status = OvertimeRequest::STATUS_PENDING;
            $overtime->save();

            $outcome = $this->approvals->start($overtime);

            if ($outcome === ApprovalWorkflowService::COMPLETED) {
                $this->finalise($overtime, null);
            } else {
                $overtime->save();
            }

            return $overtime->refresh();
        });
    }

    /**
     * Sign the current step off. Any approver may trim the claim as they do
     * so — `$approvedMinutes` is what the system will remember, and what
     * payroll would one day read.
     */
    public function approve(
        User $user,
        OvertimeRequest $overtime,
        ?int $approvedMinutes = null,
        string $remarks = '',
    ): OvertimeRequest {
        if ($overtime->status !== OvertimeRequest::STATUS_PENDING) {
            abort(409, 'This request is not waiting for approval.');
        }

        if ($approvedMinutes !== null && $approvedMinutes <= 0) {
            throw ValidationException::withMessages([
                'approved_minutes' => 'Approved minutes must be more than zero.',
            ]);
        }

        if ($approvedMinutes !== null && $approvedMinutes > $overtime->requested_minutes) {
            throw ValidationException::withMessages([
                'approved_minutes' => 'Approved minutes cannot exceed the minutes requested.',
            ]);
        }

        return DB::transaction(function () use ($user, $overtime, $approvedMinutes, $remarks) {
            if ($approvedMinutes !== null) {
                $overtime->approved_minutes = $approvedMinutes;
                $overtime->save();
            }

            $outcome = $this->approvals->approve($overtime, $user, $remarks);

            if ($outcome === ApprovalWorkflowService::COMPLETED) {
                $this->finalise($overtime, $user);
            } else {
                if ($remarks !== '') {
                    $overtime->remarks = $remarks;
                }
                $overtime->save();
            }

            return $overtime->refresh();
        });
    }

    public function reject(User $user, OvertimeRequest $overtime, string $remarks = ''): OvertimeRequest
    {
        if ($overtime->status !== OvertimeRequest::STATUS_PENDING) {
            abort(409, 'This request is not waiting for approval.');
        }

        return DB::transaction(function () use ($user, $overtime, $remarks) {
            $this->approvals->reject($overtime, $user, $remarks);

            $overtime->status = OvertimeRequest::STATUS_REJECTED;
            $overtime->rejected_at = now();
            $overtime->rejected_by = $user->id;
            $overtime->remarks = $remarks === '' ? null : $remarks;
            // A refusal is not payable, and there is nothing to release —
            // overtime has no balance to give back.
            $overtime->approved_minutes = null;
            $overtime->payroll_eligible = false;
            $overtime->save();

            event(new OvertimeDecided($overtime, OvertimeDecided::REJECTED, $user));

            return $overtime->refresh();
        });
    }

    public function cancel(User $user, OvertimeRequest $overtime, string $remarks = ''): OvertimeRequest
    {
        if (! $overtime->isOpen()) {
            abort(409, 'Only a draft or a pending request can be cancelled.');
        }

        $wasPending = $overtime->isPending();

        return DB::transaction(function () use ($overtime, $wasPending, $remarks) {
            if ($wasPending) {
                $this->approvals->abandon($overtime);
            }

            $overtime->status = OvertimeRequest::STATUS_CANCELLED;
            $overtime->cancelled_at = now();
            if ($remarks !== '') {
                $overtime->remarks = $remarks;
            }
            $overtime->payroll_eligible = false;
            $overtime->save();

            return $overtime->refresh();
        });
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The shared tail of "the chain ran out of approvers".
     *
     * @param  User|null  $approver  null when nobody had to be asked
     */
    private function finalise(OvertimeRequest $overtime, ?User $approver): void
    {
        $overtime->status = OvertimeRequest::STATUS_APPROVED;
        $overtime->approved_at = now();
        $overtime->approved_by = $approver?->id;
        $overtime->current_approval_step = null;

        // If nobody trimmed it while passing through, the grant is the claim.
        $overtime->approved_minutes = $overtime->approved_minutes ?? $overtime->requested_minutes;

        // The one line that makes this payable — and the ONLY one. Payroll
        // reads this flag; it never re-derives eligibility from status, so
        // "only approved overtime is paid" is a fact about one column.
        $overtime->payroll_eligible = true;

        $overtime->save();

        // Raised here rather than in approve(): this is the one place an
        // overtime request becomes approved, whether the answer came from
        // the last approver or from a workflow with nobody left to ask.
        event(new OvertimeDecided($overtime, OvertimeDecided::APPROVED, $approver));
    }

    private function attendanceIdFor(Employee $employee, string $date): ?int
    {
        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->value('id');
    }

    private function employeeFor(User $user): Employee
    {
        $employee = $user->employee;

        if ($employee === null) {
            abort(403, 'This account is not linked to an employee record.');
        }

        return $employee;
    }
}
