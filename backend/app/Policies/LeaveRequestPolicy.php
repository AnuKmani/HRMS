<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Approval\ApprovalWorkflowService;
use App\Support\Visibility;

/**
 * Leave: self-service at the edges, row-level everywhere else.
 *
 * Four groups of question, and each has exactly one answer:
 *
 *  - **The employee** may create, edit (while a draft), submit and cancel
 *    their own — and may never approve one. That last rule is checked here
 *    and again inside ApprovalWorkflowService, because "nobody signs off on
 *    their own absence" is too important to rest on a single gate.
 *
 *  - **An approver** may act only while they are the current step's resolved
 *    approver. Not "anybody holding leave.approve" — the coarse permission is
 *    the door to the endpoint, and [approve()] is what decides whether this
 *    particular person, on this particular request, right now.
 *
 *  - **HR Admin** (through `leave.manage`) may edit and cancel anything, and
 *    read anything Visibility::leaveIsVisible() admits them to.
 *
 *  - **Reading** fails closed exactly as attendance does: `leave.view` is
 *    what lets an Employee open their own history, so it cannot also mean
 *    "read the company's".
 */
class LeaveRequestPolicy
{
    /**
     * Resolved by the container rather than by the method signature.
     *
     * Gate's `callPolicyMethod()` invokes a policy as
     * `$policy->{$ability}($user, ...$arguments)` — it does not consult the
     * container for extra parameters. A third parameter typed
     * `ApprovalWorkflowService` therefore arrives as *nothing*, and the first
     * approval attempt dies with an ArgumentCountError instead of a 403. The
     * policy is constructed by `Gate::resolvePolicy()`, which does go through
     * the container, so this is where the dependency can be injected
     * honestly.
     */
    public function __construct(private readonly ApprovalWorkflowService $approvals) {}

    /**
     * Coarse gate for the collection. Granted to the ordinary Employee role
     * on purpose — without it they could not fetch their own history — which
     * is why Visibility narrows the rows rather than trusting the permission.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('leave.view');
    }

    public function view(User $user, LeaveRequest $leaveRequest): bool
    {
        return Visibility::leaveIsVisible($user, $leaveRequest);
    }

    public function create(User $user): bool
    {
        return $user->can('leave.create') && $user->employee !== null;
    }

    /**
     * Edit a draft: your own, or any, with `leave.manage`.
     *
     * The split of responsibilities is deliberate and applies to `submit()` and
     * `cancel()` too: **this file answers "who", the service answers "state"**.
     * Deciding here that a non-draft is a 403 would hand a caller "This action
     * is unauthorized" for what is really "Only a draft can be edited. Cancel
     * it and start again." — a message that tells them what to do instead of
     * what they did wrong. LeaveRequestService refuses the state, and refuses
     * it as a 409.
     *
     * What *is* enforced here is the thing a service call cannot decide: is
     * this caller allowed to be editing this record at all.
     */
    public function update(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->owns($user, $leaveRequest) || $user->can('leave.manage');
    }

    /**
     * Submitting is the same privilege as editing: it is yours, or you are HR
     * pushing somebody else's through.
     *
     * Not draft-only here either — see update(). A second submit attempt
     * should read "already submitted", not "unauthorized".
     */
    public function submit(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->owns($user, $leaveRequest) || $user->can('leave.manage');
    }

    /**
     * Act on the approval step this request is waiting on.
     *
     * Three conditions and none of them is optional:
     *
     *  1. the coarse permission — the route carries it too, so this is the
     *     second time it is asked, not the first;
     *  2. the request must actually be pending — approving an already
     *     decided request is a 409 in the service, but a 403 here says the
     *     caller was never in a position to act;
     *  3. the actor must be the resolved approver of the *current* step, and
     *     must not be the requester.
     *
     * Condition 3 is what makes "approval access only when they are the
     * appropriate current approver" a fact about this file rather than a
     * comment in the docs.
     */
    public function approve(User $user, LeaveRequest $leaveRequest): bool
    {
        if (! $user->can('leave.approve')) {
            return false;
        }

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            return false;
        }

        if ($user->employee?->id === $leaveRequest->employee_id) {
            return false;
        }

        return $this->approvals->actorMatchesCurrent($leaveRequest, $user);
    }

    /**
     * Rejecting requires everything approving requires. A step that cannot be
     * approved by this actor cannot be rejected by them either — otherwise
     * "not the approver" would mean "may not say yes" but still "may say no".
     */
    public function reject(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->approve($user, $leaveRequest);
    }

    /**
     * Cancel: your own, or anything with `leave.manage`. Approved, rejected
     * and LOP requests stay where they are — but that is the *service's*
     * refusal to make, as a 409 that names the state, not this policy's
     * refusal to let the attempt through. See update() for why.
     */
    public function cancel(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->owns($user, $leaveRequest) || $user->can('leave.manage');
    }

    /**
     * Read the medical certificate attached to a request the caller may read.
     *
     * No separate "may see medical documents" grant exists, on purpose: the
     * certificate is no less sensitive than the leave record it hangs off, so
     * the question is the same one — may you see this request? See
     * docs/SECURITY.md.
     */
    public function viewCertificate(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->view($user, $leaveRequest);
    }

    /**
     * File or replace a certificate: your own request, or HR's on your
     * behalf.
     *
     * "Who" and nothing else — deliberately. Whether this leave *type* wants
     * a certificate at all, and whether the request is in a state that can
     * still accept one, are state questions, and LeaveRequestService answers
     * both with a proper message (422 for the type, 409 for the state). A
     * policy that pre-empted them would hand every refusal the same bare
     * "This action is unauthorized", which is the exact outcome the
     * policy-answers-who / service-answers-state split exists to avoid.
     */
    public function uploadCertificate(User $user, LeaveRequest $leaveRequest): bool
    {
        return $this->owns($user, $leaveRequest) || $user->can('leave.manage');
    }

    private function owns(User $user, LeaveRequest $leaveRequest): bool
    {
        return $user->employee !== null
            && $user->employee->id === $leaveRequest->employee_id;
    }
}
