<?php

namespace App\Events;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An expense claim reached a terminal answer: approved or rejected.
 *
 * Carried alongside {@see LeaveDecided} and {@see OvertimeDecided} rather
 * than under one generic decision event, so each listener reads like the
 * message it sends instead of opening with a type check.
 *
 * The event deliberately carries the *claim*, not the amount: the
 * notification that goes out is a pointer to a screen that re-reads the
 * figure under the recipient's own permissions, and the amount must not be
 * duplicated into an inbox row the API would never have handed to them.
 */
class ExpenseDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly Expense $expense,
        public readonly string $decision,
        public readonly ?User $actor = null,
    ) {}
}
