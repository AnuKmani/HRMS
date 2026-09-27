<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

/**
 * Master data: one gate, no rows.
 *
 * A department has no owner and no tenant — either you may see the
 * organisation's departments or you may not. Row-level rules here would be
 * invented rather than derived, so they are left out until a real one exists.
 *
 * Routes still carry `permission:departments.*` as the coarse gate; this
 * policy is what answers `authorize()` and keeps a future code path that
 * forgets the middleware honest.
 */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('departments.view');
    }

    public function view(User $user, Department $department): bool
    {
        return $user->can('departments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('departments.manage');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->can('departments.manage');
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->can('departments.manage');
    }
}
