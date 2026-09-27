<?php

namespace App\Policies;

use App\Models\ApprovalWorkflow;
use App\Models\User;

/**
 * Approval chains are the configuration of who may say yes to what.
 *
 * Two permissions rather than one, because reading a chain and editing it are
 * different questions: `approvals.view` lets a manager see why their request
 * went to HR before it reached them, while `approvals.manage` decides that it
 * will. Held by HR Admin and Super Admin — changing who signs off on an
 * absence is a policy change, not a daily task.
 *
 * There is no `delete()`. A chain referenced by an in-flight request has to
 * stay (see ApprovalWorkflowService: the definition is read once at submit),
 * and retiring one is an `update()` to `status`.
 */
class ApprovalWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('approvals.view');
    }

    public function view(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->can('approvals.view');
    }

    public function create(User $user): bool
    {
        return $user->can('approvals.manage');
    }

    public function update(User $user, ApprovalWorkflow $approvalWorkflow): bool
    {
        return $user->can('approvals.manage');
    }
}
