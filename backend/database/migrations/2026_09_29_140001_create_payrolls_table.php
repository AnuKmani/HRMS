<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row = one employee, one calendar month of pay.
     *
     * Two decisions shape this table and everything that reads it:
     *
     *  - **Every figure is `DECIMAL(12,2)`.** Money is never a float column,
     *    anywhere in this schema. `lop_divisor` is DECIMAL(8,4) because it is
     *    a *ratio* (30, 26.0833, the working days of February) rather than a
     *    figure anybody is paid, and four places is what makes
     *    `basic / divisor * days` reproducible to the cent.
     *
     *  - **The row is a snapshot, and `payroll_items` is the itemisation of
     *    it.** The two are written together by PayrollService inside one
     *    transaction, so `total_deductions` can never disagree with the lines
     *    that add up to it. Recalculation rewrites both; a locked row writes
     *    neither again.
     *
     * `lop_days` + `lop_divisor` + `basic_salary` are stored *with* the
     * amounts rather than left to be re-derived, because the divisor comes
     * from a setting an operator can retune next week: without these three
     * numbers on the row, next week's setting would silently change how
     * last week's slip explained itself.
     *
     * `approved_at` deliberately does not exist. Phase 8 ships no approval
     * step for payroll - see `status` below - and a column nothing writes is
     * a promise about a workflow nobody built.
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('payroll_year');
            $table->unsignedTinyInteger('payroll_month');

            // The window the row was calculated against, materialised rather
            // than recomputed: a leap February recomputed next year is a
            // different window from the one the figures were produced under.
            $table->date('period_start');
            $table->date('period_end');

            /* --------------------------------------------------- earnings */
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('total_allowances', 12, 2)->default(0);
            $table->decimal('overtime_amount', 12, 2)->default(0);
            $table->decimal('bonus_amount', 12, 2)->default(0);
            $table->decimal('gross_salary', 12, 2)->default(0);

            /* ---------------------------------------- what was subtracted */
            // Unpaid days: Loss of Pay conversions *and* approved leave on a
            // type flagged `is_paid = false`. Both are the same act - days
            // that are not paid for - so they share one day count and one
            // amount, while `payroll_items` keeps them apart by `code`.
            $table->decimal('lop_days', 6, 2)->default(0);

            // The divisor those days were divided by, frozen. See the class
            // note above.
            $table->decimal('lop_divisor', 8, 4)->default(30);

            // Approved minutes actually paid, frozen beside their amount.
            $table->unsignedInteger('overtime_minutes')->default(0);

            $table->decimal('lop_amount', 12, 2)->default(0);
            $table->decimal('loan_deduction', 12, 2)->default(0);
            $table->decimal('advance_deduction', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2)->default(0);

            /* ---------------------------------------------------- status */
            // draft | calculated | reviewed | processed | locked
            //
            // `draft` is the state a row is born in: processing creates it,
            // fills it and promotes it to `calculated` inside one transaction,
            // so it is only ever observed from outside when there was nothing
            // to calculate - an employee whose `salary` is null. That gap is
            // exactly what `draft` is for: "this person has no pay row yet,
            // and here is why".
            $table->string('status', 20)->default('draft')->index();

            // Who moved it, and when - one pair per transition that can never
            // be undone, so a locked payroll names the person who locked it
            // rather than leaving the answer to a log file that does not
            // exist yet.
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The duplicate guard the spec asks for, at the schema level:
            // two rows for one person in one month would split one salary
            // into two slips nobody could reconcile.
            $table->unique(
                ['employee_id', 'payroll_year', 'payroll_month'],
                'payroll_emp_period_uk',
            );

            $table->index(['payroll_year', 'payroll_month', 'status'], 'payroll_period_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
