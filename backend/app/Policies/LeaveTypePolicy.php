<?php

namespace App\Policies;

use App\Models\LeaveType;
use App\Models\User;

/**
 * Leave types are policy, not data — which is exactly why they are gated.
 *
 * Changing Sick Leave's entitlement, switching on carry-forward, requiring a
 * certificate or pointing a type at a shorter approval chain are all changes
 * to what the organisation's rules *are*. Reading them is as ordinary as
 * reading a form (`leave.view`), and writing them is HR's alone
 * (`leave.manage`).
 *
 * No row-level check follows from that: a leave type has no owner, and
 * "which leave types may this HR Admin see?" is not a question with a
 * useful answer.
 */
class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('leave.view');
    }

    public function view(User $user, LeaveType $leaveType): bool
    {
        return $user->can('leave.view');
    }

    public function create(User $user): bool
    {
        return $user->can('leave.manage');
    }

    public function update(User $user, LeaveType $leaveType): bool
    {
        return $user->can('leave.manage');
    }

    /**
     * A leave type with requests hanging off it cannot be removed, and a
     * policy is the wrong place to decide that — `restrictOnDelete` on the
     * foreign key already refuses it at the database, with a message that
     * names the actual obstacle. Deactivation is the `update()` above.
     */
    public function delete(User $user, LeaveType $leaveType): bool
    {
        return $user->can('leave.manage');
    }
}
