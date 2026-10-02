<?php

namespace App\Events;

use App\Models\Payroll;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payslip was produced for an employee and now exists to be read.
 *
 * Raised **once, when a payroll row is first created** — a re-run of the
 * same period would fire it for every row again, and "your payslip is
 * available" arriving four times because payroll recalculated on the 28th
 * is how a notification gets muted on the 29th.
 *
 * The event carries the payroll row and nothing about it. The listener
 * writes an inbox row with a reference to the slip; the salary figure
 * itself is never copied in, because the inbox is a list endpoint that
 * would then hold a number the API gates behind `payroll.view` (see the
 * `notifications` migration for the rule this is an instance of).
 *
 * After commit: a listener that read the row mid-transaction would be
 * announcing a payslip that a rollback was about to unmake.
 */
class SalarySlipPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Payroll $payroll) {}
}
