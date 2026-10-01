<?php

namespace App\Policies;

use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Visibility;

/**
 * One person's employment file: open your own, and HR's if you are HR.
 *
 * Three questions, each with exactly one answer:
 *
 *  - **The collection** is `viewAny` — `documents.view`, the coarse gate
 *    that nearly everybody holds, because nearly everybody should be able
 *    to see their own file. It says nothing about *which rows*, which is
 *    Visibility's answer and is asked in both the list and here so the two
 *    cannot drift.
 *
 *  - **A row** is your own, or somebody else's with `documents.manage`.
 *    The second half is the only door in the building that opens a
 *    colleague's passport, and it is not `employees.view`, not being their
 *    reporting manager, not `expenses.approve`. See
 *    {@see Visibility::mayViewOthersDocuments()} for why it is deliberately
 *    narrower than every other module in this codebase.
 *
 *  - **Filing** is `documents.create` for yourself and `documents.manage`
 *    on top for anybody else. The *target* is known to the form request
 *    rather than to this policy — `authorize('create', Model::class)`
 *    receives no employee — so the rule itself lives in
 *    {@see Visibility::mayFileDocumentsFor()} and both ask it.
 *
 * Verification and archiving are separate doors again:
 *
 *  - `documents.verify` decides whether a document is genuine. Never your
 *    own, whoever holds it: signing off on the evidence you supplied is
 *    not a check, and refusing it here costs nothing because HR verifies
 *    other people's paperwork as a matter of course.
 *  - `documents.delete` *archives* — see EmployeeDocumentController for
 *    why nothing is ever physically removed.
 *
 * State (may this `pending` document still be verified, is this row already
 * archived) is the service's answer and a 409 naming the state, not this
 * policy's 403. That split is the one every policy here keeps.
 */
class EmployeeDocumentPolicy
{
    /**
     * Coarse gate for the collection — held by the ordinary Employee on
     * purpose, without which they could not read their own file.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

    public function view(User $user, EmployeeDocument $document): bool
    {
        return Visibility::employeeDocumentIsVisible($user, $document);
    }

    /**
     * Coarse "may file at all" — the half of the answer that needs no
     * target. Whether they may file for *this* employee is
     * Visibility::mayFileDocumentsFor(), asked by
     * StoreEmployeeDocumentRequest once the payload has named them.
     */
    public function create(User $user): bool
    {
        return $user->can('documents.create');
    }

    public function update(User $user, EmployeeDocument $document): bool
    {
        return Visibility::employeeDocumentIsVisible($user, $document);
    }

    /**
     * The protected file itself, and nothing more than the row already
     * grants: your own document's bytes are as open as its metadata, and a
     * colleague's bytes are exactly as closed as their passport number.
     *
     * A separate permission here — the way `expenses.receipts.view` sits
     * one step beyond a claim — would not buy anything: the row *is* the
     * sensitive disclosure, so gating the file a step further would protect
     * the scan while leaving the number readable next to it.
     */
    public function viewFile(User $user, EmployeeDocument $document): bool
    {
        return $this->view($user, $document);
    }

    /**
     * Accept a document as genuine.
     *
     * `documents.verify` on top of being able to read the row, plus one
     * condition that is not a permission at all: never your own. The
     * permission says "this role checks paperwork"; it cannot say "not the
     * paperwork you supplied", and that is a fact about the document's
     * owner rather than about the caller's rights.
     */
    public function verify(User $user, EmployeeDocument $document): bool
    {
        if (! $user->can('documents.verify')) {
            return false;
        }

        if (! Visibility::employeeDocumentIsVisible($user, $document)) {
            return false;
        }

        return ! $this->owns($user, $document);
    }

    /**
     * Refusing a document is the same decision as accepting it, asked from
     * the other side. A caller who may not say yes may not say no either —
     * otherwise "not the verifier" would mean one thing for approval and
     * its opposite for rejection.
     */
    public function reject(User $user, EmployeeDocument $document): bool
    {
        return $this->verify($user, $document);
    }

    /**
     * Archive — remove from the active list, keep the row and the file.
     *
     * Not routed through `employeeDocumentIsVisible()` on purpose: this is
     * an act *on* somebody's record rather than a read of it, so it asks
     * the explicit grant alone. An account holding `documents.delete` is
     * being told it may take documents out of circulation; an account that
     * may merely read its own must not be able to do the same to itself
     * and then wonder where it went.
     */
    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $user->can('documents.delete');
    }

    /**
     * The expiry report — what is about to lapse across the workforce.
     *
     * Its own permission because the question is about everybody rather
     * than about you: an Employee holds `documents.view` for their own
     * file and has no business seeing that forty-nine other passports
     * expire in March.
     */
    public function expiryReport(User $user): bool
    {
        return $user->can('documents.expiry.view');
    }

    private function owns(User $user, EmployeeDocument $document): bool
    {
        return $user->employee !== null
            && $user->employee->id === $document->employee_id;
    }
}
