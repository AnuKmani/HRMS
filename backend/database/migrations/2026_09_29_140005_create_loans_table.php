<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee loans and salary advances - one table, because they are one
     * object.
     *
     * `loan_type` is the only thing that differs between "a loan" and "a
     * salary advance", and it is a label rather than a rule: both are a
     * principal repaid in installments from future pay, both walk the same
     * draft -> pending -> approved -> active -> completed lifecycle, and both
     * are deducted by the same installment query in PayrollService. Two
     * tables would have meant two policies, two services and two sets of
     * tests for the same arithmetic, and the day the finance team wants a
     * third label ("relocation advance") would have been the day a third
     * copy was written.
     *
     * The one place the label *does* matter is the payslip: `loan` sums into
     * `payrolls.loan_deduction` and `salary_advance` into
     * `payrolls.advance_deduction`, so a reader can tell a long-term
     * repayment from an advance recovered this month.
     *
     * `outstanding_balance` is a running figure, not a derivation. It is
     * decremented by LoanService when an installment is deducted and
     * incremented when one is released by a recalculation, so "how much is
     * left?" is one indexed read instead of summing installments that may
     * themselves be in flux. The installments remain the detail; this column
     * is the headline, and the two are written inside the same transaction.
     *
     * Approval is a single-step decision recorded on the row (`approved_by`,
     * `approved_at`) rather than the Phase 6 materialised chain. That chain
     * exists to answer "which of several people signs off next?"; a loan
     * has exactly one approver (HR / Payroll) and no ordering to express,
     * so wiring it up would add configuration and a workflow row without
     * adding a decision point. If a multi-step chain is ever wanted,
     * ApprovalWorkflowService can be widened to take this subject - the
     * lifecycle here already has the states it would drive.
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->string('loan_type', 20)->default('loan');   // loan | salary_advance
            $table->string('reference', 40)->nullable();

            $table->decimal('principal_amount', 12, 2);
            $table->decimal('installment_amount', 12, 2);
            $table->unsignedSmallInteger('number_of_installments');

            // The first installment's due date. Later due dates are derived
            // from it by LoanService rather than stored as a schedule table:
            // n dates for n installments are computed, not entered, and a
            // user editing one date by hand would break the monthly cadence
            // the payroll period assumes.
            $table->date('start_date');

            $table->decimal('outstanding_balance', 12, 2);

            // draft | pending | approved | active | completed | rejected | cancelled
            //
            // `approved` and `active` are deliberately distinct: a loan
            // approved today with a start date next month is approved but
            // not yet being repaid, and treating those as one state would
            // either deduct from a loan that has not started or refuse a
            // deduction that is due.
            $table->string('status', 20)->default('draft')->index();

            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'status'], 'loan_emp_status_idx');
            $table->index(['status', 'start_date'], 'loan_status_start_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
