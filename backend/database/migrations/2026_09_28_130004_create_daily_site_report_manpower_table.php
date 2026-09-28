<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manpower categories for a daily site report — one row per category.
     *
     * `category` is a free string, NOT a foreign key and NOT an ENUM, and
     * that is a deliberate choice about where the vocabulary lives. A fixed
     * enum would have to be edited in code (and re-deployed) the first time
     * a site reports "scaffolders", "bar benders" or "HSE officers"; a
     * foreign key to a lookup table would put a master-data module, its
     * screens, its seeders and its permission gates between a supervisor and
     * the line they are trying to record. So the *shape* is normalised —
     * one row per head-count, so it can be summed and compared — while the
     * *words* stay with the person reporting.
     *
     * **Configurability:** Flutter offers a suggested list (see
     * `mobile/lib/features/site_reports/domain/report_vocabulary.dart`) purely
     * as a convenience for typing the same six words every day; the field is
     * free text and nothing on the server rejects a category it has not seen
     * before. If a deployment later wants the list enforced, the smallest
     * correct change is a `reporting.manpower_categories` setting read by the
     * store/update requests — no schema change, because this table already
     * stores whatever category it is given.
     *
     * `total_manpower` is NOT stored here: it is the sum of these rows,
     * written once onto `daily_site_reports` when the report is saved.
     */
    public function up(): void
    {
        Schema::create('daily_site_report_manpower', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_site_report_id')
                ->constrained('daily_site_reports')
                ->cascadeOnDelete();

            $table->string('category', 60);

            // `count`, not `headcount`/`total`: it is the count *of this
            // category*, and the report-level sum is the separate
            // `daily_site_reports.total_manpower`. Quoted by the grammar,
            // so the name is safe as a column.
            $table->unsignedInteger('count')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['daily_site_report_id', 'sort_order'], 'dsrm_report_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_site_report_manpower');
    }
};
