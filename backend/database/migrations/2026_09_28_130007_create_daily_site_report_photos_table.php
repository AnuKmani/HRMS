<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photographs attached to a daily site report.
     *
     * Identical in shape to `site_activity_report_photos`, and deliberately
     * a separate table rather than a shared `report_photos` with a
     * polymorphic `reportable_id`/`reportable_type`: the two reports are
     * different documents with different policies, and a polymorphic parent
     * makes "which reports may this photo travel with?" a question answered
     * by string comparison in a policy instead of a foreign key the database
     * can check. Two small tables is the cheaper lie.
     *
     * Same storage rules: private disk, name minted by ReportPhotoStore from
     * a SelfieSanitizer re-encode, EXIF and GPS stripped, path never present
     * in a response, bytes never in the database.
     */
    public function up(): void
    {
        Schema::create('daily_site_report_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daily_site_report_id')
                ->constrained('daily_site_reports')
                ->cascadeOnDelete();

            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->string('caption', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['daily_site_report_id', 'sort_order'], 'dsrp_report_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_site_report_photos');
    }
};
