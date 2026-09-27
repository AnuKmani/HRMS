<?php

namespace App\Services\Leave;

use App\Events\LeaveConvertedToLop;
use App\Models\ApprovalRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Approval\ApprovalWorkflowService;
use App\Services\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to `leave_requests`, in one place.
 *
 * The constraint is the same one AttendanceService works under and for the
 * same reason: these rows are an employee's word being turned into a payroll
 * input, so the sequence — how many days, is there balance, does it overlap,
 * who signs off, when is the certificate due — has to happen in one order
 * with one implementation. A controller that assembled the row itself would
 * eventually grow a second caller that got one of those steps wrong.
 *
 * Order inside `submit()`, and why:
 *
 *   1. recompute the day count. The holiday calendar is editable; the number
 *      stored when the draft was written may no longer be right, and this is
 *      the last moment before it starts costing somebody money.
 *   2. refuse a range that overlaps another request. Drafts count, because a
 *      draft that could be submitted twice would be a second claim on the
 *      same days.
 *   3. refuse a range the leave type caps out at.
 *   4. reserve the balance — AFTER the range checks, so a person who is
 *      refused for overlapping never has their pot touched at all.
 *   5. freeze the certificate deadline.
 *   6. materialise the approval chain and open its first step.
 *
 * If step 6 finds nothing to ask (every step resolved to nobody), the request
 * approves itself through the same code path a real approval takes — there is
 * no second, special "auto-approved" branch to keep in step with the first.
 *
 * The class is also the single entry point the deadline job calls, which is
 * what makes LOP conversion auditable: `convertToLop()` is the only way that
 * status is ever written.
 */
final class LeaveRequestService
{
    public function __construct(
        private readonly LeaveDayCalculator $days,
        private readonly LeaveBalanceService $balances,
        private readonly ApprovalWorkflowService $approvals,
        private readonly SickCertificateStore $certificates,
        private readonly SettingsService $settings,
    ) {}

    /* ------------------------------------------------------------ writing */

    /**
     * @param  array<string, mixed>  $data  validated by StoreLeaveRequest
     */
    public function create(User $user, array $data): LeaveRequest
    {
        $employee = $this->employeeFor($user);
        $type = $this->leaveType($data);

        return DB::transaction(function () use ($employee, $type, $data) {
            $leave = new LeaveRequest([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'site_id' => $data['site_id'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'reason' => $this->reason($data),
                'status' => LeaveRequest::STATUS_DRAFT,
            ]);

            $leave->requested_days = $this->recalculate($leave);
            $this->assertNoOverlap($leave);
            $this->assertWithinCap($type, $leave);

            $leave->save();

            return $leave;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, LeaveRequest $leave, array $data): LeaveRequest
    {
        if (! $leave->isDraft()) {
            abort(409, 'Only a draft can be edited. Cancel it and start again.');
        }

        // A PUT may name a new type or say nothing at all. Only resolve the
        // one that was sent — `leaveType()` treats an absent key as a lookup
        // of nothing and would reject the update as "type does not exist"
        // even though the caller was changing, say, only the end date.
        $type = array_key_exists('leave_type_id', $data)
            ? $this->leaveType($data)
            : $leave->leaveType;

        return DB::transaction(function () use ($leave, $type, $data) {
            $leave->fill([
                'leave_type_id' => $type->id,
                'site_id' => $data['site_id'] ?? $leave->site_id,
                'start_date' => $data['start_date'] ?? $leave->start_date,
                'end_date' => $data['end_date'] ?? $leave->end_date,
                'reason' => array_key_exists('reason', $data) ? $this->reason($data) : $leave->reason,
            ]);

            $leave->requested_days = $this->recalculate($leave);
            $this->assertNoOverlap($leave);
            $this->assertWithinCap($type, $leave);

            $leave->save();

            return $leave;
        });
    }

    /**
     * Send a draft to the approvers.
     */
    public function submit(User $user, LeaveRequest $leave): LeaveRequest
    {
        if (! $leave->isDraft()) {
            abort(409, 'This request has already been submitted.');
        }

        return DB::transaction(function () use ($leave) {
            $leave->loadMissing(['leaveType', 'employee']);

            // Recompute — see the order note above.
            $leave->requested_days = $this->recalculate($leave);

            if ($leave->requested_days <= 0) {
                throw ValidationException::withMessages([
                    'start_date' => 'Every day in that range falls on a weekend or a holiday.',
                ]);
            }

            $this->assertNoOverlap($leave);
            $this->assertWithinCap($leave->leaveType, $leave);

            $leave->submitted_at = now();
            $leave->status = LeaveRequest::STATUS_PENDING;

            $leave->certificate_due_at = $this->certificateDueDate($leave);

            // Reserve BEFORE the chain is materialised: if there is not
            // enough balance, nobody should have been asked to approve
            // anything.
            $this->balances->reserve(
                $leave->employee,
                $leave->leaveType,
                (int) $leave->start_date->year,
                (float) $leave->requested_days,
            );

            $outcome = $this->approvals->start(
                $leave,
                $leave->leaveType->approval_workflow_id,
            );

            if ($outcome === ApprovalWorkflowService::COMPLETED) {
                $this->finaliseApproval($leave, null);
            } else {
                $leave->save();
            }

            return $leave->refresh();
        });
    }

    /**
     * The current approver signs the current step off.
     */
    public function approve(User $user, LeaveRequest $leave, string $remarks = ''): LeaveRequest
    {
        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            abort(409, 'This request is not waiting for approval.');
        }

        return DB::transaction(function () use ($user, $leave, $remarks) {
            $outcome = $this->approvals->approve($leave, $user, $remarks);

            if ($outcome === ApprovalWorkflowService::COMPLETED) {
                $this->finaliseApproval($leave, $user);
            } else {
                $leave->save();
            }

            return $leave->refresh();
        });
    }

    public function reject(User $user, LeaveRequest $leave, string $remarks = ''): LeaveRequest
    {
        if ($leave->status !== LeaveRequest::STATUS_PENDING) {
            abort(409, 'This request is not waiting for approval.');
        }

        return DB::transaction(function () use ($user, $leave, $remarks) {
            $this->approvals->reject($leave, $user, $remarks);

            $leave->status = LeaveRequest::STATUS_REJECTED;
            $leave->rejected_at = now();
            $leave->rejected_by = $user->id;
            $leave->remarks = $remarks === '' ? null : $remarks;
            $leave->save();

            $leave->loadMissing(['leaveType', 'employee']);

            // Nobody is being paid for a refusal — the reservation goes back
            // so the days are bookable again.
            $this->balances->release(
                $leave->employee,
                $leave->leaveType,
                (int) $leave->start_date->year,
                (float) $leave->requested_days,
            );

            return $leave->refresh();
        });
    }

    public function cancel(User $user, LeaveRequest $leave, string $remarks = ''): LeaveRequest
    {
        if (! $leave->isOpen()) {
            abort(409, 'Only a draft or a pending request can be cancelled.');
        }

        $wasPending = $leave->isPending();

        return DB::transaction(function () use ($leave, $wasPending, $remarks) {
            if ($wasPending) {
                $this->approvals->abandon($leave);
            }

            $leave->status = LeaveRequest::STATUS_CANCELLED;
            $leave->cancelled_at = now();
            $leave->remarks = $remarks === '' ? $leave->remarks : $remarks;
            $leave->save();

            if ($wasPending) {
                $leave->loadMissing(['leaveType', 'employee']);

                $this->balances->release(
                    $leave->employee,
                    $leave->leaveType,
                    (int) $leave->start_date->year,
                    (float) $leave->requested_days,
                );
            }

            return $leave->refresh();
        });
    }

    /* -------------------------------------------------------- certificate */

    /**
     * File the medical certificate this leave type demands.
     *
     * Allowed while the request is open or already approved — the deadline
     * runs *after* the absence, so by definition most certificates arrive
     * once the decision has been made.
     */
    public function storeCertificate(User $user, LeaveRequest $leave, UploadedFile $file): LeaveRequest
    {
        if (! $leave->requiresDocument()) {
            abort(422, 'This leave type does not require a medical certificate.');
        }

        if (in_array($leave->status, [LeaveRequest::STATUS_REJECTED, LeaveRequest::STATUS_CANCELLED, LeaveRequest::STATUS_LOP], true)) {
            abort(409, 'A certificate can no longer be added to this request.');
        }

        $leave->loadMissing('employee');

        return DB::transaction(function () use ($leave, $file) {
            $previous = $leave->certificate_path;

            $leave->certificate_path = $this->certificates->store($file, $leave->employee);
            $leave->certificate_original_name = mb_substr($file->getClientOriginalName(), 0, 255);
            $leave->certificate_mime = (string) $file->getMimeType();
            $leave->certificate_size = (int) $file->getSize();
            $leave->certificate_uploaded_at = now();
            // The job re-reads this: a certificate filed after a missed run
            // must stop the conversion, and clearing the marker is how the
            // job knows to look again rather than trusting a stale pass.
            $leave->certificate_checked_at = null;
            $leave->save();

            // The superseded file goes only once its replacement is safely on
            // disk — deleting first would leave a window with neither.
            $this->certificates->delete($previous);

            return $leave;
        });
    }

    /* --------------------------------------------------------------- LOP */

    /**
     * Convert an overdue, certificate-less request into Loss of Pay.
     *
     * The ONLY writer of the `lop` status, which is what makes the rule
     * auditable and the deadline job idempotent: once this has run the row no
     * longer matches the job's query, and a second run finds nothing.
     *
     * What happens, in order:
     *
     *  1. status becomes `lop` with `lop_days` / `lop_reason` / `lop_applied_at`
     *     — the payroll input, recorded as data rather than derived later from
     *     a rule that may since have changed;
     *  2. the balance reservation is released, because those days are no
     *     longer paid sick leave — they are unpaid days, and holding both
     *     would charge the pot twice;
     *  3. the approval chain is closed, since there is nothing left to
     *     approve;
     *  4. `LeaveConvertedToLop` is dispatched after commit, so the audit and
     *     notification phases can subscribe to it without this class knowing
     *     anything about either.
     */
    public function convertToLop(LeaveRequest $leave, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $reason) {
            $wasApproved = $leave->status === LeaveRequest::STATUS_APPROVED;
            $wasPending = $leave->status === LeaveRequest::STATUS_PENDING;

            $leave->loadMissing(['leaveType', 'employee']);

            $leave->status = LeaveRequest::STATUS_LOP;
            $leave->lop_days = $leave->requested_days;
            $leave->lop_reason = $reason;
            $leave->lop_applied_at = now();
            $leave->certificate_checked_at = now();
            $leave->current_approval_step = null;
            $leave->save();

            // Checked BEFORE the status was rewritten above — `isPending()`
            // would already be false by now, and a chain left half-open on a
            // request nobody can act on again is a queue that never drains.
            if ($wasPending || $wasApproved) {
                $this->approvals->abandon($leave);
            }

            $this->balances->release(
                $leave->employee,
                $leave->leaveType,
                (int) $leave->start_date->year,
                (float) $leave->requested_days,
                $wasApproved,
            );

            event(new LeaveConvertedToLop($leave));

            return $leave;
        });
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The shared tail of "the chain ran out of approvers".
     *
     * @param  User|null  $approver  null when nobody had to be asked
     */
    private function finaliseApproval(LeaveRequest $leave, ?User $approver): void
    {
        $leave->status = LeaveRequest::STATUS_APPROVED;
        $leave->approved_at = now();
        $leave->approved_by = $approver?->id;
        $leave->current_approval_step = null;
        $leave->save();

        $leave->loadMissing(['leaveType', 'employee']);

        // Pending becomes used in one movement. `release()` is not involved:
        // the days were always going somewhere, they were only waiting.
        $this->balances->commit(
            $leave->employee,
            $leave->leaveType,
            (int) $leave->start_date->year,
            (float) $leave->requested_days,
        );
    }

    /**
     * Recompute and store the day count. Returns it so a caller can refuse a
     * zero before persisting.
     */
    private function recalculate(LeaveRequest $leave): float
    {
        return $this->days->count(
            $leave->start_date->toDateString(),
            $leave->end_date->toDateString(),
            $leave->site_id,
        );
    }

    /**
     * A date range may be held by only one live request at a time.
     */
    private function assertNoOverlap(LeaveRequest $leave): void
    {
        $overlaps = LeaveRequest::query()
            ->where('employee_id', $leave->employee_id)
            ->whereKeyNot($leave->getKey())
            ->whereIn('status', LeaveRequest::OCCUPYING)
            ->where('start_date', '<=', $leave->end_date)
            ->where('end_date', '>=', $leave->start_date)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'start_date' => 'You already have leave covering part of that range.',
            ]);
        }
    }

    private function assertWithinCap(?LeaveType $type, LeaveRequest $leave): void
    {
        if ($type === null || ! $type->capsRequests()) {
            return;
        }

        if ((float) $leave->requested_days > $type->maximum_days_per_request) {
            throw ValidationException::withMessages([
                'end_date' => sprintf(
                    '%s allows at most %d day(s) in a single request.',
                    $type->name,
                    $type->maximum_days_per_request,
                ),
            ]);
        }
    }

    /**
     * The last day the certificate may arrive: the end of the absence plus
     * the type's deadline (falling back to the organisation-wide setting).
     *
     * Counted from `end_date` rather than from `submitted_at` because the
     * rule is "N days after returning", and a request filed a fortnight
     * before the leave would otherwise be overdue on the day it was written.
     */
    private function certificateDueDate(LeaveRequest $leave): ?string
    {
        if (! $leave->requiresDocument()) {
            return null;
        }

        $deadline = $leave->leaveType->documentDeadlineDays($this->settings);

        return $leave->end_date->addDays($deadline)->toDateString();
    }

    private function reason(array $data): ?string
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        return $reason === '' ? null : mb_substr($reason, 0, 1000);
    }

    private function leaveType(array $data): LeaveType
    {
        $type = LeaveType::query()
            ->active()
            ->find($data['leave_type_id'] ?? null);

        if ($type === null) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'The selected leave type does not exist or is not active.',
            ]);
        }

        return $type;
    }

    private function employeeFor(User $user): Employee
    {
        $employee = $user->employee;

        if ($employee === null) {
            abort(403, 'This account is not linked to an employee record.');
        }

        return $employee;
    }

    /**
     * The materialised chain, for the timeline a detail screen draws.
     *
     * @return array<int, ApprovalRecord>
     */
    public function timeline(LeaveRequest $leave): array
    {
        return $this->approvals->history($leave)->all();
    }
}
