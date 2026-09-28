<?php

namespace App\Services\Payroll;

use App\Models\SalaryCertificateRequest;
use App\Models\User;

/**
 * The decision half of a salary certificate: request, approve, reject,
 * cancel, and record that a document was issued.
 *
 * A single-step decision, deliberately, and deliberately *not* wired into
 * ApprovalWorkflowService. That engine exists to express "which of several
 * people signs off next, in what order" - a materialised chain with its own
 * table, its own step counter and its own resume rules. A salary certificate
 * has exactly two parties: the person who wants it and the person who may
 * grant it. There is no ordering to express and no second opinion to record,
 * so a chain would add a workflow row, a configuration screen and a set of
 * step-resolution tests in exchange for a decision point that does not
 * exist. `approved_by` says who said yes, which is the same audit fact the
 * chain would have stored at a great deal more length.
 *
 * The same reasoning covers loans - see LoanService. If either ever grows a
 * genuine second approver, ApprovalWorkflowService can be widened to take it
 * as a subject; the status vocabularies below already have the states it
 * would drive.
 *
 * State conflicts are 409s that name the state and permission failures are
 * 403s from the policy - the Phase 7 split, kept because "you may not" and
 * "that cannot happen any more" are different sentences and a client cannot
 * word a retry correctly if the server conflates them.
 */
final class SalaryCertificateService
{
    /**
     * Ask for one.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): SalaryCertificateRequest
    {
        return SalaryCertificateRequest::create([
            'employee_id' => (int) $data['employee_id'],
            'request_date' => $data['request_date'] ?? today()->toDateString(),
            'purpose' => $data['purpose'],
            'status' => SalaryCertificateRequest::STATUS_PENDING,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Sign it off.
     *
     * The approver may not be the requester. The rule is checked here as
     * well as in the policy because it is a property of *approval* rather
     * than of any one screen: an admin endpoint, a seeder and a future
     * console script should all be refused for the same reason.
     */
    public function approve(
        SalaryCertificateRequest $request,
        User $actor,
        ?string $remarks = null,
    ): SalaryCertificateRequest {
        if (! $request->isPending()) {
            abort(409, 'Only a pending request can be approved. This one is '.$request->status.'.');
        }

        if ($request->employee_id === $actor->employee?->id) {
            abort(403, 'You cannot approve your own salary certificate request.');
        }

        $request->status = SalaryCertificateRequest::STATUS_APPROVED;
        $request->approved_by = $actor->id;
        $request->approved_at = now();

        if ($remarks !== null && $remarks !== '') {
            $request->remarks = $remarks;
        }

        $request->save();

        return $request;
    }

    public function reject(
        SalaryCertificateRequest $request,
        User $actor,
        ?string $remarks = null,
    ): SalaryCertificateRequest {
        if (! $request->isPending()) {
            abort(409, 'Only a pending request can be rejected. This one is '.$request->status.'.');
        }

        if ($request->employee_id === $actor->employee?->id) {
            abort(403, 'You cannot reject your own salary certificate request.');
        }

        $request->status = SalaryCertificateRequest::STATUS_REJECTED;
        $request->rejected_at = now();

        if ($remarks !== null && $remarks !== '') {
            $request->remarks = $remarks;
        }

        $request->save();

        return $request;
    }

    /**
     * Withdraw one - the requester's own escape hatch, only while nothing
     * has been decided. An approved certificate is HR's record as much as
     * the employee's, and letting the requester cancel it afterwards would
     * let a decision be undone by the person it was made about.
     */
    public function cancel(SalaryCertificateRequest $request): SalaryCertificateRequest
    {
        if ($request->status !== SalaryCertificateRequest::STATUS_PENDING) {
            abort(409, 'Only a pending request can be cancelled. This one is '.$request->status.'.');
        }

        $request->status = SalaryCertificateRequest::STATUS_CANCELLED;
        $request->cancelled_at = now();
        $request->save();

        return $request;
    }

    /**
     * Record that a document has actually been produced - once.
     *
     * Called by the PDF endpoint immediately before rendering. The document
     * itself is never stored (see SalaryCertificatePdf), so this is the only
     * trace that one ever existed, and it doubles as the idempotency marker
     * for a GET that has a side effect: the first render moves the row to
     * `generated`, every later render finds it already there and writes
     * nothing. That is the same shape as Phase 6's `certificate_checked_at`,
     * and for the same reason - a marker that moves on every read would
     * answer "when did somebody last look?" while pretending to answer
     * "when was this issued?".
     */
    public function markGenerated(SalaryCertificateRequest $request): SalaryCertificateRequest
    {
        if ($request->status === SalaryCertificateRequest::STATUS_GENERATED) {
            return $request;
        }

        if (! $request->isIssuable()) {
            abort(409, 'A certificate can only be issued once it has been approved. This request is '.$request->status.'.');
        }

        $request->status = SalaryCertificateRequest::STATUS_GENERATED;
        $request->generated_at = now();
        $request->save();

        return $request;
    }
}
