<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A site visit is a bounded episode: "I was at Site 7 from 11:20 to
     * 12:05 to look at the pour". It is NOT a track of where somebody was
     * in between — there is no path, no interval sampling and no background
     * capture anywhere in this schema, deliberately (docs/SECURITY.md).
     *
     * Two coordinate pairs, mirroring `attendances`, and the same DECIMAL
     * precision so both tables round identically. `client_event_id` gives
     * offline capture the same replay-safety the attendance table has.
     */
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
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

            // `datetime`, never `timestamp` — see the note on
            // `check_in_at` in the attendances migration: a NOT NULL
            // timestamp column would be given an implicit
            // `ON UPDATE CURRENT_TIMESTAMP`, and ending a visit would
            // rewrite the moment it started.
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();

            /* ------------------------------------------------------ start */
            $table->decimal('start_latitude', 10, 7)->nullable();
            $table->decimal('start_longitude', 10, 7)->nullable();
            $table->decimal('start_accuracy', 8, 2)->nullable();
            $table->decimal('start_distance', 10, 2)->nullable();

            /* -------------------------------------------------------- end */
            $table->decimal('end_latitude', 10, 7)->nullable();
            $table->decimal('end_longitude', 10, 7)->nullable();
            $table->decimal('end_accuracy', 8, 2)->nullable();
            $table->decimal('end_distance', 10, 2)->nullable();

            $table->string('purpose', 150);
            $table->string('remarks', 500)->nullable();

            // open | completed | cancelled
            $table->string('status', 20)->default('open')->index();

            // Start and end are two independently queued events, so they get
            // two independently replayable keys — see the note on the
            // attendance table for why one column would not do.
            $table->uuid('client_event_id')->nullable()->unique();
            $table->uuid('end_client_event_id')->nullable()->unique();

            $table->timestamps();

            $table->index(['employee_id', 'started_at'], 'sv_emp_started_idx');
            $table->index(['site_id', 'started_at'], 'sv_site_started_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
