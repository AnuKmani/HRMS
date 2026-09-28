<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily site reports — the official record of one site-day.
     *
     * `created_by` rather than `employee_id`: this document is authored by
     * whoever prepares it (normally the site's supervisor), and the
     * authorship is what the row-level policy checks. It is still derived
     * from the authenticated session on the server, never from the payload.
     *
     * **One per site per date.** The unique index below is the whole
     * answer to "who prepares the daily report for a site?" — two of them
     * for the same day would leave a reader unable to tell which is the
     * record, and no application-level check survives two concurrent
     * requests. The service returns a 422 on `report_date` when the row
     * already exists, so the user sees a field error instead of a 500 from
     * the driver, and the index exists so that answer is *true* rather
     * than merely intended.
     *
     * Manpower, materials and equipment are NOT columns here. They live in
     * `daily_site_report_manpower`, `daily_site_report_materials` and
     * `daily_site_report_equipment` as child rows, because a report is read
     * as "how many masons and how many electricians", "how much cement and
     * in what unit" — a single total or a comma-joined note cannot be
     * summed, compared between days, or corrected one line at a time.
     * `total_manpower` on this row is the *derived* sum, written by
     * DailySiteReportService from those children so a reader does not have
     * to join to know the headline number.
     *
     * `approved_at` is reserved. Phase 7 deliberately ships no approval
     * action: submit is the last transition this phase offers, and the
     * column exists so adding approval later is a migration-free change
     * rather than a schema change on a table that already holds reports.
     *
     * `status` is written by DailySiteReportService alone — draft |
     * submitted — and is never set from a client payload.
     */
    public function up(): void
    {
        Schema::create('daily_site_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();

            $table->foreignId('site_id')
                ->constrained('sites')
                ->restrictOnDelete();

            $table->date('report_date');

            $table->unsignedInteger('total_manpower')->default(0);

            $table->text('work_planned');
            $table->text('work_completed');

            $table->string('safety_observations', 1000)->nullable();
            $table->string('delays', 1000)->nullable();
            $table->string('issues', 1000)->nullable();
            $table->string('remarks', 500)->nullable();

            // draft | submitted. Approval is a later phase.
            $table->string('status', 20)->default('draft')->index();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->timestamps();

            // The business rule, enforced where it cannot be argued with.
            $table->unique(['site_id', 'report_date'], 'dsr_site_date_unique');

            $table->index(['project_id', 'report_date'], 'dsr_project_date_idx');
            $table->index(['created_by', 'report_date'], 'dsr_creator_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_site_reports');
    }
};
