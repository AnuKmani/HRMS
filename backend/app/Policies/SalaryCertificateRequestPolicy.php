<?php

namespace App\Policies;

use App\Models\SalaryCertificateRequest;
use App\Models\User;
use App\Support\Visibility;

/**
 * Salary certificates: ask for your own, decide on everybody's.
 *
 * Four groups of question:
 *
 *  - **The employee** may request one about themselves, read the outcome and
 *    withdraw it while it is still pending. `salary_certificates.view` does
 *    double duty as the right to ask - an employee cannot be granted a
 *    permission to request a document about their own salary that they are
 *    then refused for exercising.
 *
 *  - **A decision-maker** holds `salary_certificates.manage` and may approve
 *    or reject, but never their own - checked here and again in
 *    SalaryCertificateService, because self-approval on a document that
 *    states your own salary to a third party is exactly the sort of rule
 *    that should survive a refactor.
 *
 *    Whether the row is *still* pending is a third condition of the same
 *    three, and it lives here too - see the note on `approve`.
 *
 *  - **Reading** other people's requests narrows through
 *    {@see Visibility::salaryCertificateRequestIsVisible()}, which fails
 *    closed to own-only: only `salary_certificates.manage` reaches past it.
 *
 *  - **The PDF** (`pdf`) asks no question the underlying request does not
 *    already answer. There is no `salary_certificates.pdf` grant, because a
 *    certificate's contents - name, grade, salary, service - are the same
 *    disclosure as the row that produced it. Whether an approved request may
 *    still be issued is SalaryCertificateService's 409.
 */
class SalaryCertificateRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('salary_certificates.view');
    }

    public function view(User $user, SalaryCertificateRequest $request): bool
    {
        return Visibility::salaryCertificateRequestIsVisible($user, $request);
    }

    /**
     * Ask. Requires an employee record for the same reason a loan does: the
     * document is about a person's employment and salary, and an account
     * with no HR row has neither to print.
     */
    public function create(User $user): bool
    {
        return $user->can('salary_certificates.view') && $user->employee !== null;
    }

    /**
     * Sign it off. Coarse permission, still pending, and not the requester's
     * own - the three conditions, none optional. Refusing on state *here*
     * rather than leaving it to the service is deliberate and matches
     * LoanPolicy::approve and LeaveRequestPolicy: the policy answers every
     * question it can about this row for this caller, and a decision that
     * has already been made reads as "you were never in a position to act
     * on this". Service-level 409s then cover the race the policy cannot
     * see - two approvers in the same second.
     */
    public function approve(User $user, SalaryCertificateRequest $request): bool
    {
        if (! $user->can('salary_certificates.manage')) {
            return false;
        }

        if (! $request->isPending()) {
            return false;
        }

        return $user->employee?->id !== $request->employee_id;
    }

    /**
     * Refusing requires everything approving requires - see LoanPolicy::reject
     * for why the two are the same test.
     */
    public function reject(User $user, SalaryCertificateRequest $request): bool
    {
        return $this->approve($user, $request);
    }

    /**
     * Withdraw: your own while nothing has been decided, or HR at any point.
     * State remains the service's 409.
     */
    public function cancel(User $user, SalaryCertificateRequest $request): bool
    {
        $owns = $user->employee !== null && $user->employee->id === $request->employee_id;

        return $owns || $user->can('salary_certificates.manage');
    }

    /**
     * Render the document. The row-level question only - a request you may
     * read is a certificate you may hold, and one you may not read cannot be
     * rendered for you regardless of what you ask for.
     */
    public function pdf(User $user, SalaryCertificateRequest $request): bool
    {
        return $this->view($user, $request);
    }
}
