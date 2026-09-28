<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requests for a signed salary certificate: an employee asks, HR decides,
     * the document is produced.
     *
     * `status` walks pending -> approved -> generated, with rejected and
     * cancelled as terminal alternatives. The two timestamps that follow an
     * approval are separate columns rather than one `decided_at` because
     * "when was it approved?" and "when was the document first produced?"
     * are different facts about different actors, and only the second one
     * tells you when a reference first existed.
     *
     * `generated_at` doubles as the idempotency marker for on-demand PDF
     * generation - the same trick Phase 6 uses for `certificate_checked_at`
     * on sick leave. The PDF is never stored (see SalaryCertificatePdf), so
     * `generated_at` is written the first time a document is rendered for an
     * approved request and left alone afterwards: it answers "has this
     * certificate ever been issued?", and writing it on every read would
     * make the answer "whenever somebody last looked", which is not a fact
     * worth having.
     *
     * There is no `reference_number` column. The reference is derived from
     * the primary key (`SAL-CERT-{id}`), which is unique, stable, orderable
     * and cannot be handed out twice - a minted column would need its own
     * uniqueness rule and its own collision handling for no gain.
     *
     * There is no signature column and no signature image. A certificate
     * carries a printed authorised-signatory line and an explicit note that
     * it is not digitally signed; see docs/API_DOCUMENTATION.md for why
     * pretending otherwise would be worse than saying so.
     */
    public function up(): void
    {
        Schema::create('salary_certificate_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->date('request_date');
            $table->string('purpose', 255);

            // pending | approved | rejected | generated | cancelled
            $table->string('status', 20)->default('pending')->index();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('generated_at')->nullable();

            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'status'], 'scr_emp_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_certificate_requests');
    }
};
