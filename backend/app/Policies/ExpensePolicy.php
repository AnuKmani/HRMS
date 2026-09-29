<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Services\Approval\ApprovalWorkflowService;
use App\Support\Visibility;

/**
 * Expense claims: self-service at the edges, a chain in the middle, and
 * evidence gated one step further still.
 *
 * Four groups of question, each with exactly one answer:
 *
 *  - **The employee** may file their own claim, edit it while it is a draft,
 *    submit it and withdraw it before a decision. They may never approve one
 *    — checked here and again inside ApprovalWorkflowService, because "nobody
 *    signs off on their own spend" is too important to rest on a single gate.
 *
 *  - **An approver** may act only while the claim is pending, is not their
 *    own, and they are the resolved approver of the *current* step.
 *    `expenses.approve` is the door; conditions 2 and 3 decide whether this
 *    particular person, on this particular claim, right now. That is what
 *    makes "supervisor scope" a fact about this file rather than a comment
 *    in the docs: a Project Manager holding the permission is still refused
 *    on a claim whose current link resolved to somebody else's line manager.
 *
 *  - **`expenses.manage`** may push a claim through, withdraw one and
 *    correct a draft after the fact. It does not make them an approver —
 *    that is what the chain's last link asks for, and it is the same
 *    permission EXP-STD resolves, so the two cannot drift apart.
 *
 *  - **Reading** fails closed to *your own*, exactly as payroll and loans
 *    do and deliberately unlike leave and overtime: see
 *    {@see Visibility::mayViewOthersExpenses()} for why a claim against the
 *    company's money gets the tighter answer.
 *
 * **Receipts are gated one step beyond the claim that carries them.** Your
 * own receipts read through ownership — you filed them, you may re-open
 * them — but *somebody else's* receipt is a document, and opening one needs
 * `expenses.receipts.view` on top of being allowed to read the claim. Being
 * shown a list of claims and being handed the invoice behind one are
 * different acts, which is why they are different permissions.
 *
 * State (may a draft still be edited, may a pending claim be cancelled) is
 * ExpenseService's answer, and a 409 naming the state rather than a 403.
 */
class ExpensePolicy
{
    /**
     * Injected through the container for the same reason LeaveRequestPolicy
     * injects it: Gate's `callPolicyMethod()` passes only the route's own
     * arguments, so the service has to arrive from `Gate::resolvePolicy()`
     * rather than from the method signature.
     */
    public function __construct(private readonly ApprovalWorkflowService $approvals) {}

    /**
     * Coarse gate for the collection. Granted to the ordinary Employee on
     * purpose — without it they could not fetch their own history — which is
     * why Visibility narrows the rows rather than trusting the permission.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('expenses.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        return Visibility::expenseIsVisible($user, $expense);
    }

    public function create(User $user): bool
    {
        return $user->can('expenses.create') && $user->employee !== null;
    }

    /**
     * Edit a draft: your own, or any with `expenses.manage`.
     *
     * **This file answers "who", the service answers "state"** — the split
     * every policy in this codebase keeps. Refusing a non-draft here would
     * hand a caller "This action is unauthorized" for what is really "Only a
     * draft claim can be edited. Cancel it and file a new one." — a message
     * that tells them what to do instead of what they did wrong.
     */
    public function update(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) || $user->can('expenses.manage');
    }

    /**
     * Submitting is the same privilege as editing: it is yours, or you are
     * the back office pushing somebody else's through.
     *
     * Not draft-only here either — see update(). A second submit attempt
     * should read "already submitted", not "unauthorized".
     */
    public function submit(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) || $user->can('expenses.manage');
    }

    /**
     * Act on the approval link this claim is waiting on.
     *
     * Four conditions and none of them is optional:
     *
     *  1. the coarse permission — the route carries it too, so this is the
     *     second time it is asked, not the first;
     *  2. the claim must actually be pending — approving a decided claim is
     *     a 409 in the service, but a 403 here says the caller was never in
     *     a position to act;
     *  3. never your own claim, whoever the chain resolves to;
     *  4. you must be the resolved approver of the *current* link.
     */
    public function approve(User $user, Expense $expense): bool
    {
        if (! $user->can('expenses.approve')) {
            return false;
        }

        if ($expense->status !== Expense::STATUS_PENDING) {
            return false;
        }

        if ($user->employee?->id === $expense->employee_id) {
            return false;
        }

        return $this->approvals->actorMatchesCurrent($expense, $user);
    }

    /**
     * Rejecting requires everything approving requires. A link that cannot be
     * approved by this actor cannot be rejected by them either — otherwise
     * "not the approver" would mean "may not say yes" but still "may say no".
     */
    public function reject(User $user, Expense $expense): bool
    {
        return $this->approve($user, $expense);
    }

    /**
     * Cancel: your own, or anything with `expenses.manage`. Approved and
     * rejected claims stay where they are — but that is the *service's*
     * refusal to make, as a 409 that names the state, not this policy's
     * refusal to let the attempt through. See update() for why.
     */
    public function cancel(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) || $user->can('expenses.manage');
    }

    /* ------------------------------------------------------------ receipts */

    /**
     * Open a receipt: one attached to a claim you may read.
     *
     * Ownership is the short way in — your own receipts are yours with no
     * extra grant — and everything else needs `expenses.receipts.view` on top
     * of the claim being visible, because the file behind a claim is a
     * separate disclosure from the claim's own numbers.
     */
    public function viewReceipt(User $user, Expense $expense, ExpenseReceipt $receipt): bool
    {
        if ((int) $receipt->expense_id !== (int) $expense->id) {
            return false;
        }

        if ($this->owns($user, $expense)) {
            return true;
        }

        return $user->can('expenses.receipts.view') && $this->view($user, $expense);
    }

    /**
     * Attach or remove evidence: your own draft, or anybody's with
     * `expenses.manage`.
     *
     * Deliberately "who" and nothing else — whether this claim is in a state
     * that can still accept a receipt is ExpenseService's 409 with a message
     * naming the state, for the same reason uploadCertificate() on leave is.
     */
    public function storeReceipt(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) || $user->can('expenses.manage');
    }

    public function deleteReceipt(User $user, Expense $expense, ExpenseReceipt $receipt): bool
    {
        if ((int) $receipt->expense_id !== (int) $expense->id) {
            return false;
        }

        return $this->storeReceipt($user, $expense);
    }

    private function owns(User $user, Expense $expense): bool
    {
        return $user->employee !== null
            && $user->employee->id === $expense->employee_id;
    }
}
