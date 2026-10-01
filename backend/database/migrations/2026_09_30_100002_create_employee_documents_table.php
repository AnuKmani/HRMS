<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee documents — passports, Emirates IDs, visas, contracts and
     * everything else a person's employment file is made of.
     *
     * Two halves to the row: what the document *says* (number, issue date,
     * expiry date) and where its bytes live. The second half is deliberately
     * a private path under `storage/app/private/employee-documents/`, minted
     * by EmployeeDocumentStore from a UUID and never derived from the
     * client's filename.
     *
     * **No API response ever echoes `path`.** The document resource reports
     * `has_file`, `original_name`, `mime_type` and `size_bytes`; the only way
     * to the bytes is `GET /api/v1/employee-documents/{document}/file`,
     * behind EmployeeDocumentPolicy — own document, or `documents.manage`.
     * `original_name` is kept because a person recognises "passport-scan.jpg"
     * faster than a uuid, and it is stored as data only: it is never used to
     * open, serve or list anything.
     *
     * **The status is stored, but "expiring soon" is not.** Five statuses —
     * `pending`, `valid`, `expired`, `rejected`, `archived` — because those
     * are decisions somebody made or facts a date has already settled.
     * "Expiring soon" is neither: it is a *window* over `expiry_date` that
     * depends on this document type's warning period and today's date, so it
     * is computed by DocumentExpiryService rather than written into a column
     * that would be wrong by tomorrow. Flutter is told the computed state;
     * it is never the one that decides it.
     *
     * `expiry_notified_at` is the idempotency marker for the scheduled scan,
     * and it exists for the reason `leave_requests.certificate_checked_at`
     * does: a job that both detects and reports a condition needs a way to
     * remember it already did, or the second run reports it again. It is
     * cleared whenever `expiry_date` changes, so a re-dated document gets a
     * fresh warning instead of inheriting the old one's silence.
     *
     * `employee_id` is `restrictOnDelete`, not `cascadeOnDelete`: an
     * employment file is the record of a person who worked here, and losing
     * it because a directory row was cleaned up is exactly the "physical
     * removal of something with historical importance" the spec rules out.
     * There is no delete endpoint on employees for the same reason.
     */
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('document_type_id')
                ->constrained('document_types')
                ->restrictOnDelete();

            // NULL only when the type does not require a number — see
            // document_types.requires_document_number.
            $table->string('document_number', 100)->nullable();

            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();

            // Facts about a *private* file. Never a URL, never a public disk
            // key, never the bytes. `path` is under
            // `storage/app/private/employee-documents/{employeeId}/{uuid}`.
            $table->string('path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();

            // pending | valid | expired | rejected | archived — stored, and
            // deliberately without a MySQL ENUM: the database is asked for
            // data, not for the application's vocabulary.
            $table->string('status', 20)->default('pending');

            $table->string('notes', 1000)->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('archived_at')->nullable();

            // Set once by the scheduled scan when this document first enters
            // (or falls past) its expiry window; null means "still to be
            // said". See the class note above.
            $table->timestamp('expiry_notified_at')->nullable();

            $table->timestamps();

            // The onboarding checklist asks "does this employee hold a valid
            // document of this type?" once per requirement per employee.
            $table->index(['employee_id', 'document_type_id', 'status']);

            // The two expiry filters, and the scan's own selection.
            $table->index(['status', 'expiry_date']);
            $table->index('expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
