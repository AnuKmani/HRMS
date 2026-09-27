<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per employee per calendar day. The composite unique index is
     * not merely a convenience: it is the database's half of the duplicate
     * check-in defence, sitting behind the application's `lockForUpdate()`
     * so two phones tapping CHECK IN at the same instant produce one row and
     * one 409 rather than two overlapping days of pay.
     *
     * Coordinates and distance are DECIMAL, never FLOAT — a rounding drift in
     * a stored distance would eventually disagree with the geofence that
     * admitted it. Precision mirrors `sites` (10,7) so a check-in coordinate
     * and a site coordinate round the same way.
     *
     * `scheduled_start_at` / `scheduled_end_at` are a frozen snapshot of the
     * shift in force on the day. The shift row keeps being edited for future
     * weeks; rewriting these would quietly change what last Tuesday meant.
     *
     * There is no `attendance_locations` side table: an attendance carries
     * exactly two points (in and out) and a site visit carries two more.
     * A separate table would add a join and a second deletion story to model
     * data that is already 1:1 with the row it describes.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();

            $table->foreignId('site_id')
                ->constrained('sites')
                ->restrictOnDelete();

            // The calendar day the check-in belongs to. An overnight shift
            // checked in at 22:00 belongs to that date even though it ends
            // the following morning.
            $table->date('attendance_date');

            // `datetime`, never `timestamp`: MariaDB ships with
            // explicit_defaults_for_timestamp=0, which makes the FIRST
            // NOT NULL timestamp column of a table silently carry
            // `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
            // A check-out would then rewrite the arrival time with the
            // database clock and the day would lose its own start.
            $table->dateTime('check_in_at');
            $table->dateTime('check_out_at')->nullable();

            /* --------------------------------------------------- check-in */
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->decimal('check_in_accuracy', 8, 2)->nullable();    // metres
            $table->decimal('check_in_distance', 10, 2)->nullable();   // metres
            $table->string('check_in_selfie_path', 500)->nullable();   // private disk path

            /* -------------------------------------------------- check-out */
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->decimal('check_out_accuracy', 8, 2)->nullable();
            $table->decimal('check_out_distance', 10, 2)->nullable();

            /* -------------------------------------------------- schedule */
            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('shifts')
                ->nullOnDelete();

            $table->dateTime('scheduled_start_at')->nullable();
            $table->dateTime('scheduled_end_at')->nullable();

            /* -------------------------------------------------- computed */
            $table->unsignedInteger('working_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_departure_minutes')->default(0);

            /* ---------------------------------------------------- state */
            // present | late | incomplete | missing_checkout | manually_adjusted
            // Every value is written by AttendanceStatusCalculator alone.
            $table->string('status', 30)->default('present')->index();

            // online | offline | manual — where the record came from, kept
            // separate from `status` so an offline submission is never
            // mistaken for a different kind of day.
            $table->string('source', 20)->default('online')->index();

            $table->string('device_reference', 100)->nullable();
            $table->string('notes', 500)->nullable();

            /**
             * Idempotency key minted by the client for offline capture.
             *
             * Nullable because an online check-in has no need of one, and
             * unique because replaying the same queued event must resolve to
             * the same row rather than a second attendance. MySQL permits
             * many NULLs in a unique index, so the online path is unaffected.
             *
             * Check-in and check-out get SEPARATE keys: they are two events
             * that can each be queued, retried and lost independently, and
             * one column serving both would let a replayed check-in find a
             * row whose key a check-out had already overwritten.
             */
            $table->uuid('client_event_id')->nullable()->unique();
            $table->uuid('check_out_client_event_id')->nullable()->unique();

            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date'], 'att_employee_date_idx');
            $table->index(['attendance_date', 'status'], 'att_date_status_idx');
            $table->index(['site_id', 'attendance_date'], 'att_site_date_idx');
            $table->index(['check_in_at'], 'att_check_in_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
