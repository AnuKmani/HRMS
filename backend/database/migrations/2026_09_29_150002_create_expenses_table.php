<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expense claims — an employee's word about money spent, waiting to
     * become somebody else's money paid out.
     *
     * `employee_id` is never read from the payload: ExpenseService derives
     * it from the authenticated session, exactly as site activity reports
     * do, because "claim on behalf of a colleague" is not a feature this
     * system has.
     *
     * `project_id` / `site_id` are nullable and stay that way on purpose:
     * a plain expense (a meal, a taxi home after a late shift) has no
     * site, while a site expense has both and ExpenseService refuses a
     * pair that do not agree with each other. They are `restrictOnDelete`
     * rather than `nullOnDelete` for the same reason the site reports are
     * — a claim that silently loses the site it was booked against stops
     * being auditable, and master rows here are soft-deleted anyway.
     *
     * The approval columns mirror `leave_requests` and `overtime_requests`
     * exactly, because they are written by the same engine:
     * `current_approval_step` is the sequence number of the step waiting
     * right now (null when nothing is), and the chain itself lives in
     * `approval_records` where every other subject's chain lives. The
     * definition is frozen into those rows at submit, so editing a
     * workflow never rewrites a claim already in flight.
     *
     * `final_approved_by` rather than `approved_by` because approval here
     * is the *last* link of a chain, not the only one; who acted on each
     * individual step is on `approval_records.acted_by`.
     *
     * `amount` is DECIMAL(12,2). There is no column in this schema that
     * stores money as a float, and this one is not going to be the first.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('expense_category_id')
                ->constrained('expense_categories')
                ->restrictOnDelete();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->restrictOnDelete();

            $table->foreignId('site_id')
                ->nullable()
                ->constrained('sites')
                ->restrictOnDelete();

            $table->date('expense_date');

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('description', 500);

            // draft | pending | approved | rejected | cancelled. Written by
            // ExpenseService alone: there is no endpoint that sets an
            // arbitrary status, because "approved" here means "the chain
            // was walked end to end and every step said yes".
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedSmallInteger('current_approval_step')->nullable();

            $table->foreignId('approval_workflow_id')
                ->nullable()
                ->constrained('approval_workflows')
                ->nullOnDelete();

            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            $table->foreignId('final_approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'expense_date'], 'exp_emp_date_idx');
            $table->index(['expense_category_id', 'status'], 'exp_cat_status_idx');
            $table->index(['project_id', 'expense_date'], 'exp_project_date_idx');
            $table->index(['site_id', 'expense_date'], 'exp_site_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
