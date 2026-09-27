<?php

namespace App\Policies;

use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Approval\ApprovalWorkflowService;
use App\Support\Visibility;

/**
 * Overtime: the same shape as leave, because it is the same shape of act.
 *
 * Asking for extra minutes, editing a draft, cancelling a claim and being the
 * person who signs it off are the four questions leave already answers, and
 * answering them differently here would be a second set of rules to keep in
 * step with the first.
 *
 * The one thing overtime does *not* have is a balance to protect — there is
 * nothing to reserve on submit and nothing to release on rejection. The
 * consequence that matters, `payroll_eligible`, is written by OvertimeService
 * when a chain completes and never by anything this policy decides.
 *
 * The name is `OvertimeRequestPolicy`, not `OvertimePolicy`, because Gate
 * guesses the class from the *model*: `App\Models\OvertimeRequest` resolves to
 * `App\Policies\OvertimeRequestPolicy` and nothing else. There is no
 * registration list to add a near-miss to — an unnamed policy is simply never
 * consulted, and every ability it defines quietly answers "denied". That
 * failure is invisible in `route:list` and in `php artisan test` until
 * something actually asks the question.
 */
class OvertimeRequestPolicy
{
    /**
     * Injected here rather than taken as a method parameter: Gate calls a
     * policy as `$policy->{$ability}($user, ...$arguments)` and never
     * resolves extra type-hinted parameters, so one typed
     * `ApprovalWorkflowService` would arrive as nothing. See
     * LeaveRequestPolicy::__construct() for the same note.
     */
    public function __construct(private readonly ApprovalWorkflowService $approvals) {}

    public function viewAny(User $user): bool
    {
        return $user->can('overtime.view');
    }

    public function view(User $user, OvertimeRequest $overtimeRequest): bool
    {
        return Visibility::overtimeIsVisible($user, $overtimeRequest);
    }

    public function create(User $user): bool
    {
        return $user->can('overtime.create') && $user->employee !== null;
    }

    /**
     * Edit: your own, or any, with `overtime.manage`.
     *
     * "Who may try" lives here and "whether the state allows it" lives in
     * OvertimeService, so a second edit attempt reads "Only a draft can be
     * edited" as a 409 rather than a bare 403. See LeaveRequestPolicy::update()
     * for the full argument — it is the same one.
     */
    public function update(User $user, OvertimeRequest $overtimeRequest): bool
    {
        return $this->owns($user, $overtimeRequest) || $user->can('overtime.manage');
    }

    public function submit(User $user, OvertimeRequest $overtimeRequest): bool
    {
        return $this->owns($user, $overtimeRequest) || $user->can('overtime.manage');
    }

    /**
     * Act on the approval step this claim is waiting on — the same three
     * conditions leave applies, with overtime's own permission.
     */
    public function approve(User $user, OvertimeRequest $overtimeRequest): bool
    {
        if (! $user->can('overtime.approve')) {
            return false;
        }

        if ($overtimeRequest->status !== OvertimeRequest::STATUS_PENDING) {
            return false;
        }

        if ($user->employee?->id === $overtimeRequest->employee_id) {
            return false;
        }

        return $this->approvals->actorMatchesCurrent($overtimeRequest, $user);
    }

    public function reject(User $user, OvertimeRequest $overtimeRequest): bool
    {
        return $this->approve($user, $overtimeRequest);
    }

    public function cancel(User $user, OvertimeRequest $overtimeRequest): bool
    {
        return $this->owns($user, $overtimeRequest) || $user->can('overtime.manage');
    }

    private function owns(User $user, OvertimeRequest $overtimeRequest): bool
    {
        return $user->employee !== null
            && $user->employee->id === $overtimeRequest->employee_id;
    }
}
