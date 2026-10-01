<?php

namespace App\Policies;

use App\Models\EmployeeOnboarding;
use App\Models\User;

/**
 * Where somebody is in joining the company.
 *
 * Two doors, and the row-scoping is deliberately *not* the workforce rule
 * the rest of this application uses. A Project Manager may open the
 * directory for the people on their project, may read their attendance and
 * may sign off their leave — and none of that is a reason to be told that
 * somebody has not yet produced a visa. Onboarding says what a person has
 * and has not handed in; it is HR's to see, and each person's own to see.
 *
 * So: `onboarding.view` opens the module and narrows to your own record
 * unless `onboarding.manage` is held, and every write — moving the state
 * along, completing it — is `onboarding.manage` alone.
 *
 * **The argument is an EmployeeOnboarding, not an Employee.** The route is
 * `GET /onboarding/{employee}` because that is the question a caller asks,
 * but Laravel resolves a policy from the class of the first argument, so
 * the controller stages an unsaved `EmployeeOnboarding` carrying the
 * employee's id before authorising. Staging rather than `firstOrCreate`ing
 * matters: a GET must not write a row before anybody has decided the caller
 * may read it. See OnboardingController::show().
 *
 * State — is this status a legal move, are all mandatory requirements met —
 * is OnboardingService's answer and a 409 naming what is missing, not this
 * policy's 403. An unfinished form and a caller without the right to touch
 * it are different sentences.
 */
class EmployeeOnboardingPolicy
{
    /**
     * Coarse gate for the collection. Held by everyone, because every
     * employee should be able to ask where their own onboarding stands.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('onboarding.view');
    }

    public function view(User $user, EmployeeOnboarding $onboarding): bool
    {
        return $this->owns($user, $onboarding) || $user->can('onboarding.manage');
    }

    public function update(User $user, EmployeeOnboarding $onboarding): bool
    {
        return $user->can('onboarding.manage');
    }

    /**
     * Marking it done is the same privilege as moving it along — the
     * *check* that everything is actually in place is
     * OnboardingService::complete()'s 409, not a second permission.
     */
    public function complete(User $user, EmployeeOnboarding $onboarding): bool
    {
        return $this->update($user, $onboarding);
    }

    private function owns(User $user, EmployeeOnboarding $onboarding): bool
    {
        return $user->employee !== null
            && (int) $user->employee->id === (int) $onboarding->employee_id;
    }
}
