<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Who may read the audit trail.
 *
 * One method, one answer: `audit.view`. There is deliberately no row-level
 * test in it — see AuditLogController for why scoping an audit log to the
 * rows a caller could already see would defeat the point of having one.
 *
 * The policy exists at all (rather than leaving `viewAny` to the route's
 * `permission:` middleware) because `$this->authorize()` in the controller
 * means the rule is enforced by two mechanisms in the two places that could
 * drift, and because a future `audit.export` needs something to hang on.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->can('audit.view');
    }
}
