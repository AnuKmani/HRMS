<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photographs attached to a site activity report.
     *
     * One row per file, and the row carries only facts about a *private*
     * file — never a URL, never a public disk key, never the bytes. `path`
     * is a name under `storage/app/private/site-report-photos/`, minted by
     * ReportPhotoStore from the sanitized image `SelfieSanitizer` already
     * produces for attendance selfies: re-encoded, EXIF and GPS stripped,
     * random filename. Nothing in any API response ever echoes it; the only
     * way to a photograph is GET .../{report}/photos/{photo}, which is
     * behind the report's own policy.
     *
     * `mime_type` and `size_bytes` are recorded at upload time rather than
     * re-probed on every read, because both are decided once, in
     * ReportPhotoStore, and a file whose bytes were replaced later would be
     * described by stale values anyway — the store is the only writer.
     *
     * `sort_order` rather than a position column with gaps: a photo set is
     * an ordered gallery, not a sparse list, and renumbering on insert is
     * cheaper than a linked list nobody can render.
     */
    public function up(): void
    {
        Schema::create('site_activity_report_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_activity_report_id')
                ->constrained('site_activity_reports')
                ->cascadeOnDelete();

            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->string('caption', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['site_activity_report_id', 'sort_order'], 'sarp_report_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_activity_report_photos');
    }
};
