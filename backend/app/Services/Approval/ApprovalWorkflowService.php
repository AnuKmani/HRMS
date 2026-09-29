<?php

namespace App\Services\Approval;

use App\Models\ApprovalRecord;
use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The configurable approval engine, shared by leave, overtime and expenses.
 *
 * Nothing in this class names a role, a person or a chain. It reads whatever
 * `approval_workflows` says, copies it into `approval_records` when a request
 * is submitted, and walks that copy forwards. "Employee -> Supervisor ->
 * Project Manager -> HR" and "Employee -> HR" are therefore two rows of data,
 * not two code paths — which is the whole point of F. The third subject,
 * an expense claim, arrives as a third row of data and no new code at all:
 * every method below takes whichever subject was handed to it.
 *
 * The three rules that make it safe:
 *
 *  1. **The chain is frozen at submit.** Editing a workflow never rewrites a
 *     request already in flight; history has to be stable or it is not
 *     history.
 *  2. **Only the current step may act.** A step that is `waiting` cannot be
 *     approved out of order, and a step that has already been decided cannot
 *     be decided twice — both checked on the row read under `lockForUpdate()`.
 *  3. **Nobody approves their own request.** Checked here rather than in each
 *     policy, because it is a property of *approval* and not of any one
 *     subject. It holds even when the requester is the person the step
 *     resolves to.
 *
 * A step that cannot be resolved is *skipped*, not deleted and not left
 * hanging: a supervisor with no reporting manager would otherwise deadlock
 * every request below them. The skip is recorded with a remark so the
 * timeline still shows that the link existed and why nobody was asked.
 */
final class ApprovalWorkflowService
{
    /** The chain moved forwards but is not finished. */
    public const ADVANCED = 'advanced';

    /** Every step is decided — the subject may be finalised. */
    public const COMPLETED = 'completed';

    /** The subject was rejected or abandoned mid-chain. */
    public const STOPPED = 'stopped';

    public function forSubject(string $subjectType, ?int $workflowId = null): ApprovalWorkflow
    {
        $workflow = $workflowId !== null
            ? ApprovalWorkflow::query()
                ->active()
                ->where('subject_type', $subjectType)
                ->find($workflowId)
            : ApprovalWorkflow::query()->defaultFor($subjectType)->first();

        if ($workflow === null) {
            abort(
                422,
                $workflowId === null
                    ? 'No approval workflow is configured for this kind of request.'
                    : 'The selected approval workflow does not exist or is not active.',
            );
        }

        if ($workflow->steps->isEmpty()) {
            abort(422, "Approval workflow \"{$workflow->code}\" has no steps.");
        }

        return $workflow;
    }

    /**
     * Materialise the chain onto a subject and open the first step that can
     * actually be answered.
     *
     * @return string self::ADVANCED when somebody now holds it, self::COMPLETED
     *                when every step resolved to nobody (all skipped)
     */
    public function start(LeaveRequest|OvertimeRequest|Expense $subject, ?int $workflowId = null): string
    {
        $subjectType = $this->subjectType($subject);

        return DB::transaction(function () use ($subject, $subjectType, $workflowId) {
            // Two vocabularies, and they are not interchangeable.
            // `approval_workflows.subject_type` selects a *definition* — the
            // API validates it as `leave`, `overtime` or `expense` — while
            // `approval_records.subject_type` names the row the record hangs
            // off. Handing the record's vocabulary to forSubject() made every
            // lookup miss, and the symptom appeared three layers away as
            // "No approval workflow is configured" on the first submit.
            $workflow = $this->forSubject($this->workflowSubjectType($subject), $workflowId);

            $employee = Employee::query()->findOrFail($subject->employee_id);

            // Replacing rather than appending: `start()` is the only writer of
            // these rows, and a leftover from an earlier attempt would break
            // the (subject, sequence) unique index rather than being replaced.
            ApprovalRecord::query()->forSubject($subjectType, $subject->getKey())->delete();

            foreach ($workflow->orderedSteps() as $step) {
                ApprovalRecord::query()->create([
                    'subject_type' => $subjectType,
                    'subject_id' => $subject->getKey(),
                    'approval_workflow_id' => $workflow->id,
                    'sequence' => $step->sequence,
                    'name' => $step->name,
                    'approver_type' => $step->approver_type,
                    'approver_role' => $step->approver_role,
                    'approver_permission' => $step->approver_permission,
                    // Resolved ONCE, here. A reorganisation next month must
                    // not re-route a request that is already in somebody's
                    // queue.
                    'approver_employee_id' => $step->approver_type === ApprovalWorkflowStep::TYPE_REPORTING_MANAGER
                        ? $employee->reporting_manager_id
                        : null,
                    'status' => ApprovalRecord::STATUS_WAITING,
                ]);
            }

            $subject->approval_workflow_id = $workflow->id;
            $subject->save();

            return $this->advance($subject);
        });
    }

    /**
     * Is the actor the person the current step is waiting on?
     *
     * The read-only twin of approve()/reject(), so a policy can ask the same
     * question a transaction is about to ask — without taking a lock, and
     * without having to guess. The transaction re-checks under
     * `lockForUpdate()` anyway; this is the early, cheap answer that lets a
     * caller get a clean 403 before any work happens rather than halfway
     * through a write.
     */
    public function actorMatchesCurrent(LeaveRequest|OvertimeRequest|Expense $subject, User $actor): bool
    {
        // Self-approval first, and for the same reason it is first in
        // authorize(): it holds however the step happens to resolve, so the
        // requester's own name cannot come back as an approver even when the
        // chain points straight at them.
        if ($subject->employee_id !== null && $subject->employee_id === $actor->employee?->id) {
            return false;
        }

        $record = $this->current($subject);

        return $record !== null && $this->matches($record, $actor);
    }

    /**
     * The step currently holding this subject, if any.
     */
    public function current(LeaveRequest|OvertimeRequest|Expense $subject): ?ApprovalRecord
    {
        return ApprovalRecord::query()
            ->forSubject($this->subjectType($subject), $subject->getKey())
            ->pending()
            ->orderBy('sequence')
            ->first();
    }

    /**
     * @return Collection<int, ApprovalRecord>
     */
    public function history(LeaveRequest|OvertimeRequest|Expense $subject): Collection
    {
        return ApprovalRecord::query()
            ->forSubject($this->subjectType($subject), $subject->getKey())
            ->orderBy('sequence')
            ->get();
    }

    /**
     * Approve the current step and move the chain on.
     *
     * @return string self::ADVANCED (still waiting on somebody) or
     *                self::COMPLETED (this was the last step)
     */
    public function approve(LeaveRequest|OvertimeRequest|Expense $subject, User $actor, string $remarks = ''): string
    {
        return DB::transaction(function () use ($subject, $actor, $remarks) {
            $record = $this->currentForUpdate($subject);

            $this->authorize($subject, $record, $actor, 'approve');

            $record->update([
                'status' => ApprovalRecord::STATUS_APPROVED,
                'acted_by' => $actor->id,
                'acted_at' => now(),
                'remarks' => $remarks === '' ? null : $remarks,
            ]);

            return $this->advance($subject);
        });
    }

    /**
     * Stop the chain at the current step.
     *
     * @return string always self::STOPPED
     */
    public function reject(LeaveRequest|OvertimeRequest|Expense $subject, User $actor, string $remarks = ''): string
    {
        DB::transaction(function () use ($subject, $actor, $remarks) {
            $record = $this->currentForUpdate($subject);

            $this->authorize($subject, $record, $actor, 'reject');

            $record->update([
                'status' => ApprovalRecord::STATUS_REJECTED,
                'acted_by' => $actor->id,
                'acted_at' => now(),
                'remarks' => $remarks === '' ? null : $remarks,
            ]);

            $this->close($subject, ApprovalRecord::STATUS_SKIPPED);
        });

        return self::STOPPED;
    }

    /**
     * Close every open step without an approval — the requester cancelled.
     *
     * Deliberately takes no `User`: cancelling is not an approval act, so
     * there is no approver to record and the self-approval rule has nothing
     * to say about it.
     */
    public function abandon(LeaveRequest|OvertimeRequest|Expense $subject): void
    {
        $this->close($subject, ApprovalRecord::STATUS_SKIPPED);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Find the first `waiting` step that can actually be answered, opening it
     * and skipping any ahead of it that cannot.
     */
    private function advance(LeaveRequest|OvertimeRequest|Expense $subject): string
    {
        $subjectType = $this->subjectType($subject);

        $records = ApprovalRecord::query()
            ->forSubject($subjectType, $subject->getKey())
            ->orderBy('sequence')
            ->get();

        foreach ($records as $record) {
            if ($record->status === ApprovalRecord::STATUS_PENDING) {
                // Already holding somebody. `advance()` is only ever called
                // after a step was just decided, so reaching here means the
                // records and the subject disagree — better to leave the
                // pending step alone than to open a second one.
                return self::ADVANCED;
            }

            if ($record->status !== ApprovalRecord::STATUS_WAITING) {
                continue;
            }

            if ($this->isResolvable($record, $subject)) {
                $record->update(['status' => ApprovalRecord::STATUS_PENDING]);

                $subject->current_approval_step = $record->sequence;
                $subject->save();

                return self::ADVANCED;
            }

            $record->update([
                'status' => ApprovalRecord::STATUS_SKIPPED,
                'remarks' => $record->remarks ?? 'No approver was available for this step.',
            ]);
        }

        $subject->current_approval_step = null;
        $subject->save();

        return self::COMPLETED;
    }

    /**
     * Every open step becomes `$status`, and the subject stops waiting.
     */
    private function close(LeaveRequest|OvertimeRequest|Expense $subject, string $status): void
    {
        ApprovalRecord::query()
            ->forSubject($this->subjectType($subject), $subject->getKey())
            ->whereIn('status', [ApprovalRecord::STATUS_WAITING, ApprovalRecord::STATUS_PENDING])
            ->update([
                'status' => $status,
                'acted_at' => now(),
            ]);

        $subject->current_approval_step = null;
        $subject->save();
    }

    /**
     * Read the current step under a row lock.
     */
    private function currentForUpdate(LeaveRequest|OvertimeRequest|Expense $subject): ApprovalRecord
    {
        $record = ApprovalRecord::query()
            ->forSubject($this->subjectType($subject), $subject->getKey())
            ->pending()
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            abort(409, 'This request is not waiting on an approval.');
        }

        if ($subject->current_approval_step !== null
            && $record->sequence !== (int) $subject->current_approval_step) {
            abort(409, 'This request has moved on. Reload it and try again.');
        }

        return $record;
    }

    /**
     * Is the actor the person this step is waiting on?
     */
    private function authorize(
        LeaveRequest|OvertimeRequest|Expense $subject,
        ApprovalRecord $record,
        User $actor,
        string $action,
    ): void {
        // Before anything else, and regardless of how the step resolves: an
        // approver is never the requester. Checked against the *employee*, so
        // it holds even when the chain's only remaining step points back at
        // the person asking.
        if ($subject->employee_id !== null && $subject->employee_id === $actor->employee?->id) {
            abort(403, 'You cannot approve your own request.');
        }

        if ($this->matches($record, $actor)) {
            return;
        }

        abort(
            403,
            $action === 'approve'
                ? 'You are not the approver for this step.'
                : 'You are not the approver for this step, so you cannot reject it either.',
        );
    }

    private function matches(ApprovalRecord $record, User $actor): bool
    {
        return match ($record->approver_type) {
            ApprovalWorkflowStep::TYPE_REPORTING_MANAGER => $record->approver_employee_id !== null
                && $record->approver_employee_id === $actor->employee?->id,
            ApprovalWorkflowStep::TYPE_ROLE => $record->approver_role !== null
                && $actor->hasRole($record->approver_role),
            ApprovalWorkflowStep::TYPE_PERMISSION => $record->approver_permission !== null
                && $actor->can($record->approver_permission),
            default => false,
        };
    }

    /**
     * Could *anybody* answer this step?
     *
     * Deliberately about existence rather than about a particular actor: a
     * step whose role nobody holds is a broken chain, and skipping it is
     * better than parking every request forever on a link that can never be
     * answered.
     */
    private function isResolvable(ApprovalRecord $record, LeaveRequest|OvertimeRequest|Expense $subject): bool
    {
        return match ($record->approver_type) {
            ApprovalWorkflowStep::TYPE_REPORTING_MANAGER => $record->approver_employee_id !== null,
            ApprovalWorkflowStep::TYPE_ROLE => $record->approver_role !== null
                && Role::query()->where('name', $record->approver_role)->exists(),
            ApprovalWorkflowStep::TYPE_PERMISSION => $record->approver_permission !== null
                && Permission::query()->where('name', $record->approver_permission)->exists(),
            default => false,
        };
    }

    private function subjectType(LeaveRequest|OvertimeRequest|Expense $subject): string
    {
        return match (true) {
            $subject instanceof LeaveRequest => ApprovalRecord::TYPE_LEAVE,
            $subject instanceof OvertimeRequest => ApprovalRecord::TYPE_OVERTIME,
            $subject instanceof Expense => ApprovalRecord::TYPE_EXPENSE,
            default => throw new \InvalidArgumentException('Unsupported approval subject.'),
        };
    }

    /**
     * Which definition selector this subject's chain is looked up by.
     *
     * Deliberately a second method rather than a second value inside
     * subjectType(): one method returning two vocabularies depending on the
     * caller's intent is exactly how the two drifted apart in the first
     * place. `approval_workflows.subject_type` is `leave` / `overtime` /
     * `expense`; `approval_records.subject_type` is `leave_request` /
     * `overtime_request` / `expense`.
     */
    private function workflowSubjectType(LeaveRequest|OvertimeRequest|Expense $subject): string
    {
        return match (true) {
            $subject instanceof LeaveRequest => ApprovalWorkflow::SUBJECT_LEAVE,
            $subject instanceof OvertimeRequest => ApprovalWorkflow::SUBJECT_OVERTIME,
            $subject instanceof Expense => ApprovalWorkflow::SUBJECT_EXPENSE,
            default => throw new \InvalidArgumentException('Unsupported approval subject.'),
        };
    }
}
