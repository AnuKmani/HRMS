<?php

namespace App\Policies;

use App\Models\Designation;
use App\Models\User;

/**
 * Master data: one gate, no rows — see DepartmentPolicy for why.
 *
 * The department a designation sits under is an attribute, not an
 * authorisation boundary: a user who may manage designations may move one
 * between departments.
 */
class DesignationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('designations.view');
    }

    public function view(User $user, Designation $designation): bool
    {
        return $user->can('designations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('designations.manage');
    }

    public function update(User $user, Designation $designation): bool
    {
        return $user->can('designations.manage');
    }

    public function delete(User $user, Designation $designation): bool
    {
        return $user->can('designations.manage');
    }
}
