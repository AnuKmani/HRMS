<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The materialised copy of an approval chain — one row per (subject,
     * step), created the moment a request is submitted.
     *
     * Why materialise rather than read the definition live each time:
     *
     *  - **History must be stable.** Editing LEAVE-STD next month to add an
     *    approver must not retroactively insert a step into a request that
     *    is already halfway through. The definition is read once, at submit;
     *    this table is what actually happened.
     *  - **"Who is holding it?" is one indexed lookup** on
     *    (subject_type, subject_id, status = pending) instead of a walk
     *    through a chain that may have changed underneath.
     *  - **An approver's identity can be resolved once.** A
     *    `reporting_manager` step is expanded to the concrete employee at
     *    submit time, so a reorganisation does not hand yesterday's request
     *    to today's manager.
     *
     * `subject_type` + `subject_id` rather than two nullable foreign keys:
     * a row cannot reference leave_requests *and* overtime_requests, and a
     * polymorphic pair with a unique index on (type, id, sequence) gives the
     * same "one row per step" guarantee without a table per subject. The
     * service never queries a subject it has not first loaded, so the absent
     * referential constraint cannot orphan anything in practice — and it is
     * checked on write.
     *
     * `status` here is the *step's* status (waiting | pending | approved |
     * rejected | skipped), not the subject's. A skipped step records that a
     * link in the chain could not be resolved — a supervisor with no
     * reporting manager — rather than silently deleting it, so the timeline
     * still shows every step that was considered.
     */
    public function up(): void
    {
        Schema::create('approval_records', function (Blueprint $table) {
            $table->id();

            // leave_request | overtime_request
            $table->string('subject_type', 30)->index();
            $table->unsignedBigInteger('subject_id')->index();

            $table->foreignId('approval_workflow_id')
                ->constrained('approval_workflows')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->string('name', 100);

            $table->string('approver_type', 30);
            $table->string('approver_role', 100)->nullable();
            $table->string('approver_permission', 100)->nullable();

            // Expanded from approver_type = reporting_manager at submit time.
            $table->unsignedBigInteger('approver_employee_id')->nullable();

            // waiting | pending | approved | rejected | skipped
            $table->string('status', 20)->default('waiting')->index();

            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('acted_at')->nullable();
            $table->string('remarks', 500)->nullable();

            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_id', 'sequence'],
                'ar_subject_seq_idx',
            );
            $table->index(['subject_type', 'subject_id', 'status'], 'ar_subject_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_records');
    }
};
