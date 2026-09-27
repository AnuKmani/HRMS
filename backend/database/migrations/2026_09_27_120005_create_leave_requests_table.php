<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalised leave requests: one row, one absence, every state it can be
     * in carried on that row rather than in a status log the reader has to
     * reconstruct.
     *
     * `requested_days` is computed by LeaveDayCalculator from the date range,
     * never taken from the client — see the service for why weekends and
     * holidays are excluded. It is stored (rather than recalculated on read)
     * because the holiday calendar is editable: re-deriving it next March
     * would change what was approved last September.
     *
     * `status` is written by LeaveRequestService alone:
     *   draft | pending | approved | rejected | cancelled | lop
     *
     * The three timestamps are separate columns rather than a single
     * `decided_at`, because "when was this cancelled?" and "who approved it?"
     * have to be answerable at the same time, and the approver columns below
     * say who.
     *
     * Certificate columns describe a single uploaded file for leave types with
     * `requires_document`. They are on this row and not in a side table
     * because a request carries exactly one certificate — the same argument
     * the attendances migration makes about selfies.
     *
     * `certificate_due_at` is frozen at submit time. The deadline setting can
     * be retuned tomorrow; a request submitted under the old rule keeps the
     * date it was actually given.
     *
     * `certificate_checked_at` is the scheduler's idempotency marker: the
     * deadline job writes it whether or not it converts anything, so running
     * twice in a row (or two workers racing) cannot process the same request
     * twice. The `lop` status is the second, structural guard.
     *
     * LOP columns are the payroll hand-off. Nothing here computes pay —
     * `lop_days` + `lop_reason` + `lop_applied_at` are the input payroll will
     * consume in its own phase (docs/DATABASE.md).
     */
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('leave_type_id')
                ->constrained('leave_types')
                ->restrictOnDelete();

            // Which site the absence concerns, when it concerns one at all.
            // Nullable: annual leave is not about a site.
            $table->foreignId('site_id')
                ->nullable()
                ->constrained('sites')
                ->nullOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            $table->decimal('requested_days', 6, 2)->default(0);

            $table->string('reason', 1000)->nullable();

            $table->string('status', 20)->default('draft')->index();

            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remarks', 500)->nullable();

            /* -------------------------------------------------- workflow */
            // Denormalised from the materialised approval records so the list
            // screen can sort and filter "waiting on step 2" without a join.
            $table->unsignedSmallInteger('current_approval_step')->nullable();
            $table->foreignId('approval_workflow_id')
                ->nullable()
                ->constrained('approval_workflows')
                ->nullOnDelete();

            /* ----------------------------------------------- certificate */
            $table->string('certificate_path', 500)->nullable();
            $table->string('certificate_original_name', 255)->nullable();
            $table->string('certificate_mime', 100)->nullable();
            $table->unsignedInteger('certificate_size')->nullable();
            $table->dateTime('certificate_uploaded_at')->nullable();
            $table->date('certificate_due_at')->nullable();
            $table->dateTime('certificate_checked_at')->nullable();

            /* ------------------------------------------------------- LOP */
            $table->decimal('lop_days', 6, 2)->default(0);
            $table->string('lop_reason', 500)->nullable();
            $table->dateTime('lop_applied_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'start_date', 'end_date'], 'lr_emp_range_idx');
            $table->index(['status', 'start_date'], 'lr_status_start_idx');
            $table->index(['leave_type_id', 'status'], 'lr_type_status_idx');
            $table->index(['certificate_due_at'], 'lr_cert_due_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
