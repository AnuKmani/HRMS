<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Timesheets as DERIVED SNAPSHOTS of attendance, not a second record of
     * the working day.
     *
     * This is the decision that shapes the whole timesheet feature, so it is
     * worth stating plainly: a timesheet here does not re-state when somebody
     * arrived. `attendances` already owns that fact, immutably, with a unique
     * (employee, date) key and no override endpoint. What a timesheet adds is
     * the *project/site/shift dimensions plus a period the manager signs off*
     * — a projection of attendance onto the dimensions payroll and billing
     * need, materialised once so a month's report is one indexed read rather
     * than a month of joins recomputed on every view.
     *
     * Consequences, all deliberate:
     *
     *  - `attendance_id` points back at the row it was built from, so a
     *    mismatch is detectable rather than invisible;
     *  - regenerating a period is an UPDATE over (employee_id, timesheet_date)
     *    — the unique index makes it idempotent — never an INSERT that could
     *    duplicate a day;
     *  - check-in/out and the three minute counts are copied, not referenced,
     *    because a report must show what was true when the period was
     *    generated. The attendance row remains the source of truth and a
     *    regeneration re-copies from it.
     *
     * `status` is a *state of the day*, not an approval: `open` (no check-out
     * yet), `complete`, `incomplete` (missing a punch). Timesheet sign-off is
     * intentionally absent from Phase 6 — approving a derived snapshot asserts
     * nothing that approving the underlying attendance would not, and the
     * approval engine is already exercised end-to-end by leave and overtime.
     * The column is indexed because "show me the incomplete days in March" is
     * the query that matters.
     */
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->date('timesheet_date');

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();

            $table->foreignId('site_id')
                ->nullable()
                ->constrained('sites')
                ->nullOnDelete();

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('shifts')
                ->nullOnDelete();

            // The row this snapshot was derived from, and the seam that makes
            // regeneration auditable: a timesheet pointing at an attendance
            // id is checkable against its source, and one pointing at nothing
            // is a day generated before the attendance arrived (or whose
            // attendance was since deleted). Nullable rather than required
            // because deleting an attendance must not delete the period it
            // appeared in — the day then reads as `open` with no punches,
            // which is the truth.
            $table->foreignId('attendance_id')
                ->nullable()
                ->constrained('attendances')
                ->nullOnDelete();

            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();

            $table->unsignedInteger('working_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);

            // open | complete | incomplete
            $table->string('status', 20)->default('open')->index();

            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->unique(
                ['employee_id', 'timesheet_date'],
                'ts_emp_date_idx',
            );
            $table->index(['timesheet_date', 'status'], 'ts_date_status_idx');
            $table->index(['project_id', 'timesheet_date'], 'ts_project_date_idx');
            $table->index(['site_id', 'timesheet_date'], 'ts_site_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timesheets');
    }
};
