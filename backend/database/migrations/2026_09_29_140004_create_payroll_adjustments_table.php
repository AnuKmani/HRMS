<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-off additions to (and subtractions from) a single month's pay:
     * bonuses, other deductions and manual adjustments.
     *
     * Three kinds, one table, because they are the same transaction with
     * different signs and different labels:
     *
     *   bonus            an extra earning - a referral, a completion bonus
     *   other_deduction  a penalty, an advance recovered outside a loan, a
     *                    cost reclaimed
     *   adjustment       either direction, signed
     *
     * The spec asked for a bonus table and for "other deductions"; building
     * the second would have copied the first's approval flow, its period
     * key and its policy almost line for line. One table with a `type` keeps
     * "approve this before it reaches payroll" a single rule with one
     * endpoint pair and one policy, which is the difference between a rule
     * that is enforced and a rule that is written down twice.
     *
     * `amount` is signed only where the type allows a direction:
     * `bonus` must be > 0 and `other_deduction` must be > 0 (the sign comes
     * from the type), while `adjustment` carries its own sign and must be
     * non-zero. That is the "no arbitrary negative amounts" rule - validated
     * in StorePayrollAdjustmentRequest and again in PayrollService, because
     * a column constraint cannot know which of the three types a row is.
     *
     * `status` starts `pending` and only `approved` rows ever reach a pay
     * run. A bonus nobody signed off on is a suggestion, not a payment.
     */
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            // The period this adjustment belongs to. Required rather than
            // "next run" - an unanchored bonus is one that silently moves
            // into whatever month happens to be processed next, which is
            // exactly the sort of figure a payroll review cannot reconcile.
            $table->unsignedSmallInteger('payroll_year');
            $table->unsignedTinyInteger('payroll_month');

            $table->string('type', 20);        // bonus | other_deduction | adjustment
            $table->string('description', 255);
            $table->decimal('amount', 12, 2);

            $table->string('status', 20)->default('pending')->index();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['employee_id', 'payroll_year', 'payroll_month'],
                'padj_emp_period_idx',
            );
            $table->index(['payroll_year', 'payroll_month', 'status'], 'padj_period_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
    }
};
