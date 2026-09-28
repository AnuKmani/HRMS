<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The repayment schedule of one loan: n rows, one per installment.
     *
     * Created in full when the loan is approved, because the schedule is a
     * consequence of three numbers that cannot change afterwards (principal,
     * installment amount, count) and because a payroll run needs to know
     * *which* installment falls in this month, not just how much is left.
     *
     * `payroll_id` is the anti-double-deduction anchor: an installment is
     * claimed by exactly one payroll row, inside a `SELECT ... FOR UPDATE`
     * on a row whose `status` must still be `pending`. The pair is written
     * atomically - status `deducted` and `payroll_id` together - so a second
     * run in the same second, or a recalculation racing a process, cannot
     * both claim it. MySQL permits many NULLs in a unique index but this
     * table does not need one: a NULL `payroll_id` is by definition not
     * deducted, so there is nothing to make unique.
     *
     * Releasing a claim (a recalculation) sets `payroll_id` back to NULL and
     * `status` back to `pending` *before* the new calculation runs, so the
     * same installment is claimed again rather than lost.
     *
     * `skipped` and `adjusted` exist so an operator can stop or reshape a
     * single installment without editing the loan. Neither is written by
     * Phase 8's API - they are vocabulary a future "manage installments"
     * screen will need, and the status column is cheaper to have now than a
     * migration plus a data migration later. Payroll deducts `pending` only,
     * so an installment a human marked as anything else is never taken.
     */
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_id')
                ->constrained('loans')
                ->cascadeOnDelete();

            $table->foreignId('payroll_id')
                ->nullable()
                ->constrained('payrolls')
                ->nullOnDelete();

            $table->unsignedSmallInteger('sequence');   // 1..n
            $table->date('due_date');
            $table->decimal('amount', 12, 2);

            $table->string('status', 20)->default('pending')->index();  // pending | deducted | skipped | adjusted
            $table->dateTime('deducted_at')->nullable();

            $table->timestamps();

            $table->unique(['loan_id', 'sequence'], 'li_loan_seq_uk');
            $table->index(['status', 'due_date'], 'li_status_due_idx');
            $table->index(['payroll_id'], 'li_payroll_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};
