<?php

namespace App\Policies;

use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\Visibility;

/**
 * One person's place on one course: open your own, and HR's if you are HR.
 *
 * Four doors, and the shape is employee documents' on purpose because the
 * thing being protected is the same kind of thing — a person's competence
 * record, which in a construction company is very often the paper that
 * decides whether they may stand on a site at all.
 *
 *  - **`viewAny`** is `training.view`, the coarse gate nearly everybody
 *    holds so nearly everybody can read their own history. Which rows that
 *    means is {@see Visibility::employeeTrainingsFor()}'s answer, asked in
 *    the list and asked here again so a `show` cannot answer "yes" to a row
 *    the index hid.
 *  - **`view`** is your own, or `training.manage`. The second half is the
 *    only door in the building that opens a colleague's course record, and
 *    it is not `employees.view` and not being their reporting manager.
 *  - **`create`** is `training.assign`, deliberately without a self-service
 *    half: putting yourself on the course that certifies you is exactly the
 *    act the brief says an employee may not perform.
 *  - **`complete`** is `training.complete` and `cancel` is
 *    `training.update`, each on top of the row rule. They are separate
 *    permissions because recording a pass and taking somebody off a course
 *    are different decisions, and a role that may do one should be
 *    expressible without the other.
 *
 * The certificate file is narrower again — see {@see viewCertificate()}.
 * State (is this enrolment still editable, has it already been completed)
 * is EmployeeTrainingService's answer and a 409 naming the state, never
 * this policy's 403. That split is the one every policy here keeps.
 */
class EmployeeTrainingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('training.view');
    }

    public function view(User $user, EmployeeTraining $training): bool
    {
        return Visibility::employeeTrainingIsVisible($user, $training);
    }

    public function create(User $user): bool
    {
        return $user->can('training.assign');
    }

    public function update(User $user, EmployeeTraining $training): bool
    {
        if (! $user->can('training.update')) {
            return false;
        }

        return Visibility::employeeTrainingIsVisible($user, $training);
    }

    /**
     * Record that the course was finished.
     *
     * Its own permission beside `training.update`, and row-scoped like
     * every other act here: an account that may correct a date should not
     * thereby be able to pass somebody, nor to pass somebody it cannot
     * read.
     */
    public function complete(User $user, EmployeeTraining $training): bool
    {
        if (! $user->can('training.complete')) {
            return false;
        }

        return Visibility::employeeTrainingIsVisible($user, $training);
    }

    /**
     * Take an enrolment off the books.
     *
     * Shares `training.update` with correcting a record — both are "this
     * enrolment is wrong / should not stand" — but is a *separate ability*
     * so a future role may be given one without the other, and so the
     * endpoint's refusal names the act rather than a generic edit.
     */
    public function cancel(User $user, EmployeeTraining $training): bool
    {
        if (! $user->can('training.update')) {
            return false;
        }

        return Visibility::employeeTrainingIsVisible($user, $training);
    }

    /**
     * The certificate file itself — deliberately **not** the same answer as
     * the row.
     *
     * Your own card is as open as its metadata (`training.view`); a
     * colleague's needs `training.certificates.view`, which is HR's alone.
     * `training.manage` does not open it, and that asymmetry is the point:
     * being trusted to correct somebody's enrolment dates is not the same
     * as being handed every certificate they hold — the same step
     * `expenses.receipts.view` takes beyond a claim, taken one place where
     * the file really is the sensitive disclosure.
     */
    public function viewCertificate(User $user, EmployeeTraining $training): bool
    {
        return Visibility::maySeeCertificateFor($user, $training);
    }

    /**
     * The cross-employee "whose certificate is about to lapse" report.
     *
     * Its own permission for the reason `documents.expiry.view` has one: an
     * Employee holds `training.view` for their own history and has no
     * business seeing that forty-nine other cards expire in March.
     */
    public function expiryReport(User $user): bool
    {
        return $user->can('training.expiry.view');
    }
}
