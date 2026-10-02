<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The queued half of the export story.
     *
     * A small report is generated in the request and streamed — nobody
     * wants a spinner for fourteen rows. A large one is not: a 40,000-row
     * XLSX built inline would hold a PHP worker and a database connection
     * for a minute, and a request that long is one a load balancer will
     * cut off half-way through a file the user then cannot resume.
     *
     * So the row count decides. Over `hrms.reporting.export_max_rows` the
     * POST below parks a row here, hands it to a queued job, and answers
     * `202`; the file lands in private storage under
     * `exports/{userId}/{uuid}.{ext}` and the row flips to `ready`. Nothing
     * about the export is ever derived from the client: the filters are
     * re-run server-side from this row, so a queued export cannot be asked
     * for data the requester would be refused now.
     *
     * `path` is minted server-side and is never returned to the client —
     * only `GET /report-exports/{id}/file` streams it, after re-checking
     * that the row belongs to the caller.
     */
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('report_key', 60);
            $table->string('format', 10);
            $table->json('filters')->nullable();

            // `pending` | `ready` | `failed`
            $table->string('status', 20)->default('pending');
            $table->string('path', 512)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
