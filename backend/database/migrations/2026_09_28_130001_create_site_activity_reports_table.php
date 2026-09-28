<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site activity reports — one person's note about one site-day.
     *
     * Deliberately a *personal* record rather than an official one: the row
     * always belongs to the employee whose session created it (the server
     * derives `employee_id` from the bearer token and never reads it from
     * the payload), it can be created by anyone standing on a site they are
     * assigned to, and there is no "one per site per date" rule because
     * twelve people can be on the same site on the same day and each of
     * them has their own account of it. The official site-day record is
     * `daily_site_reports`.
     *
     * `manpower`, `materials_used` and `equipment_used` are free text here
     * and only here. A field worker filling in a note on a phone between
     * two pours is not maintaining an inventory, and the normalised child
     * rows the daily report uses (`daily_site_report_materials` and
     * friends) exist because *that* document has to be read by somebody who
     * was not there. Two shapes, two jobs — and the structured rows live
     * only on the daily report so this table stays one insert.
     *
     * `work_category` is a free string rather than a lookup or an ENUM:
     * nothing else in the system reads it, the vocabulary differs between
     * civil, MEP and finishing trades, and a table nobody can extend is a
     * table that starts lying the first time a new trade arrives.
     *
     * `progress_percentage` is an unsigned TINYINT: 0–100 is the whole
     * domain, and the database is the last place a value outside it should
     * be able to reach.
     *
     * Coordinates use the same DECIMAL(10,7) / DECIMAL(8,2) pair as
     * `attendances` and `site_visits`, so a fix recorded here and one
     * recorded at check-in round identically. They are NULLable on create —
     * a draft may be written with no fix at all — and SiteActivityReportService
     * refuses to *submit* one without them.
     */
    public function up(): void
    {
        Schema::create('site_activity_reports', function (Blueprint $table) {
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

            $table->date('report_date');

            $table->string('work_category', 60);
            $table->text('work_performed');
            $table->unsignedTinyInteger('progress_percentage')->default(0);

            $table->text('manpower')->nullable();
            $table->text('materials_used')->nullable();
            $table->text('equipment_used')->nullable();

            $table->string('issues', 1000)->nullable();
            $table->string('safety_issues', 1000)->nullable();
            $table->string('remarks', 500)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy', 8, 2)->nullable();

            // draft | submitted. Written by SiteActivityReportService alone;
            // there is deliberately no endpoint that sets an arbitrary
            // status, because "submitted" here means "the fix, the photos
            // and the words were all present when this left the phone".
            $table->string('status', 20)->default('draft')->index();
            $table->dateTime('submitted_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'report_date'], 'sar_emp_date_idx');
            $table->index(['site_id', 'report_date'], 'sar_site_date_idx');
            $table->index(['project_id', 'report_date'], 'sar_project_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_activity_reports');
    }
};
