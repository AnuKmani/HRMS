<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One enrolment of one person in one training program.
     *
     * The row is an *event*, not a certificate: it carries the dates, the
     * trainer, the result and — when the program issues one — everything
     * about the certificate that resulted. Keeping them together is what
     * lets "is this person certified?" be answered by one row rather than by
     * joining an enrolment to a document and hoping they are about the same
     * thing.
     *
     * **The certificate's bytes live exactly where a passport's do.**
     * `certificate_path` is a private path under
     * `storage/app/private/employee-documents/`, minted by
     * EmployeeDocumentStore from a UUID. There is no second store, no public
     * URL and no directory listing; the only way to the file is
     * `GET /api/v1/employee-training/{id}/file`, behind
     * EmployeeTrainingPolicy. Nothing in an API response ever echoes
     * `certificate_path` — the resource reports `has_certificate`,
     * `certificate_original_name`, `certificate_mime_type` and
     * `certificate_size` instead.
     *
     * **Seven statuses, and "expiring soon" is not one of them.**
     * `expired` is stored (it is a fact the calendar settled and the scan
     * wrote), but a certificate still *inside* its warning window is not a
     * status — it is a window over `certificate_expiry_date`, computed by
     * EmployeeTraining::certificateExpiryState() the same way
     * EmployeeDocument::expiryState() computes it. Storing that would mean a
     * column that is right today and wrong tomorrow.
     *
     * `expiry_notified_at` is the scan's idempotency marker, exactly as it
     * is on `employee_documents`: a second run finds a non-null marker and
     * says nothing.
     */
    public function up(): void
    {
        Schema::create('employee_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();
            $table->foreignId('training_program_id')
                ->constrained('training_programs')
                ->restrictOnDelete();

            $table->date('enrollment_date');
            $table->date('training_date')->nullable();
            $table->date('completion_date')->nullable();

            // Null means "the program's own provider". Resolved into the
            // resource rather than copied at write time so renaming a
            // program's trainer updates yesterday's enrolment too.
            $table->string('trainer', 200)->nullable();

            $table->string('status', 20)->default('enrolled')->index();

            // Free-form on purpose: `pass`, `distinction`, `85%` and `N/A`
            // are all things an instructor writes, and a lookup table for
            // them would be one more thing an operator cannot extend.
            $table->string('result', 60)->nullable();

            $table->string('certificate_number', 120)->nullable();
            $table->date('certificate_issue_date')->nullable();
            $table->date('certificate_expiry_date')->nullable();

            $table->string('certificate_path', 500)->nullable();
            $table->string('certificate_original_name', 255)->nullable();
            $table->string('certificate_mime_type', 120)->nullable();
            $table->unsignedBigInteger('certificate_size')->nullable();

            $table->string('remarks', 1000)->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamps();

            // One enrolment per person, per program, per day — the backstop
            // behind EmployeeTrainingService's own 409. Recertification is
            // still possible: it is a *new* row with a new enrolment date.
            //
            // Named by hand: the conventional auto-generated name runs to 78
            // characters and MariaDB's identifier limit is 64, so the
            // migration would otherwise fail on the very column it exists to
            // protect. The short name says the same thing in the index
            // namespace, which is where an operator reading `SHOW INDEX`
            // actually looks.
            $table->unique(
                ['employee_id', 'training_program_id', 'enrollment_date'],
                'employee_trainings_enrolment_unique',
            );

            $table->index(['status', 'certificate_expiry_date']);
            $table->index(['employee_id', 'status']);
            $table->index('certificate_expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_trainings');
    }
};
