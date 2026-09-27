<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Overtime requests — "I worked extra on this date", asked for and
     * decided, with the approved figure kept separately from the requested
     * one.
     *
     * The two minute columns are the point. A supervisor may grant 60 of the
     * 120 minutes asked for, and storing one number would either lose what
     * the employee originally claimed or pretend they were granted all of it.
     * Payroll reads `approved_minutes` and only when `payroll_eligible` is set.
     *
     * `payroll_eligible` is a denormalised consequence of `status = approved`,
     * not an independent flag anybody may toggle: only ApprovalWorkflowService
     * writes it, and only when a chain completes. "Only approved overtime may
     * be paid" is then a fact about one column rather than a rule that has to
     * be re-checked at every read — and Phase 6 does not pay anything at all.
     *
     * `attendance_id` is optional: overtime is normally proposed from a real
     * checked-in day, but a request may be raised for a day whose attendance
     * has not been reconciled yet, and refusing to record it would just push
     * the conversation into email.
     *
     * Same status vocabulary as leave requests, because a user who learns
     * "pending means waiting on somebody" in one module should not have to
     * unlearn it in the next. Written by OvertimeService alone.
     */
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->date('overtime_date');

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();

            $table->foreignId('site_id')
                ->nullable()
                ->constrained('sites')
                ->nullOnDelete();

            $table->foreignId('attendance_id')
                ->nullable()
                ->constrained('attendances')
                ->nullOnDelete();

            $table->unsignedInteger('requested_minutes');
            $table->unsignedInteger('approved_minutes')->nullable();

            $table->string('reason', 500);

            // draft | pending | approved | rejected | cancelled
            $table->string('status', 20)->default('draft')->index();

            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks', 500)->nullable();

            $table->unsignedSmallInteger('current_approval_step')->nullable();
            $table->foreignId('approval_workflow_id')
                ->nullable()
                ->constrained('approval_workflows')
                ->nullOnDelete();

            $table->boolean('payroll_eligible')->default(false)->index();

            $table->timestamps();

            $table->index(['employee_id', 'overtime_date'], 'ot_emp_date_idx');
            $table->index(['status', 'overtime_date'], 'ot_status_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
    }
};
