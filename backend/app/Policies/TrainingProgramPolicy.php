<?php

namespace App\Policies;

use App\Models\TrainingProgram;
use App\Models\User;

/**
 * The training catalogue: may this role read it, write it, retire it.
 *
 * Programs are *configuration*, not rows about a person — nobody's
 * competence is described by the catalogue itself — so there is no row
 * scope here at all. `training.view` opens it, `training.create` adds to it,
 * `training.update` edits it, and that is the whole answer.
 *
 * What this policy deliberately does *not* carry is a door onto an
 * individual's enrolment. Those live in EmployeeTrainingPolicy, where the
 * row is a person's course history and the narrow rule applies. Keeping the
 * two apart is what stops `training.update` (a catalogue grant) from
 * quietly becoming "may edit anybody's training record".
 *
 * Retiring is an edit rather than a delete, and there is no `delete`
 * ability: a program with cohorts behind it must not be removable, and one
 * without any has nothing to gain from a hard delete that a `retired` flag
 * does not already give.
 */
class TrainingProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('training.view');
    }

    public function view(User $user, TrainingProgram $program): bool
    {
        return $user->can('training.view');
    }

    public function create(User $user): bool
    {
        return $user->can('training.create');
    }

    public function update(User $user, TrainingProgram $program): bool
    {
        return $user->can('training.update');
    }

    /**
     * Take a program off the assign form without touching a cohort.
     *
     * Separate from `update` for the same reason document archiving is a
     * separate permission from editing: it changes what *other* people may
     * do next, not just this row.
     */
    public function retire(User $user, TrainingProgram $program): bool
    {
        return $user->can('training.manage');
    }
}
