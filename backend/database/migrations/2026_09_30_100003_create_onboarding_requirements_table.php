<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What onboarding is made of — the requirement catalogue.
     *
     * The alternative this table exists to avoid is the one everybody
     * reaches for first: nine booleans on `employees` (`has_passport`,
     * `has_visa`, ...). That shape is wrong three times over. It cannot
     * answer *why* something is outstanding ("the passport is rejected" and
     * "nobody ever asked for a passport" both read as `false`), it puts
     * process state on a person's record where a directory edit can quietly
     * clear it, and adding a tenth requirement means a migration on a table
     * every other query reads.
     *
     * So a requirement is a row, and each row says *how* its satisfaction is
     * judged — `kind`, never a hard-coded rule in a service:
     *
     *  - **`document`** — the employee holds an `employee_documents` row of
     *    `document_type_id` with status `valid`. Anything less (`pending`,
     *    `rejected`, `expired`, absent) is reported as its own state rather
     *    than as a bare "not satisfied", because "waiting for HR to verify"
     *    and "never uploaded" need different follow-ups.
     *  - **`data`** — every column named in `employee_fields` is non-empty
     *    on the employee row. This is how "personal information" and
     *    "employee photo" are checked without copying the column list into
     *    PHP, so adding a field to the requirement is a row edit.
     *  - **`bank`** — a row exists in `employee_bank_accounts`. Bank details
     *    are the one requirement whose data is *not* on the employee record
     *    at all, deliberately: see that table, and docs/SECURITY.md.
     *
     * `is_mandatory` is what "complete onboarding" is measured against.
     * An optional requirement is still tracked and still displayed — a
     * training certificate nobody has yet is worth seeing — but it never
     * blocks completion.
     *
     * `document_type_id` is nullable because two of the eight seeded
     * requirements are not documents at all.
     */
    public function up(): void
    {
        Schema::create('onboarding_requirements', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('code', 40)->unique();
            $table->string('description', 500)->nullable();

            // document | data | bank — see the class note. A plain string
            // rather than an ENUM: the database stores data, not the
            // application's vocabulary.
            $table->string('kind', 20)->default('document');

            $table->foreignId('document_type_id')
                ->nullable()
                ->constrained('document_types')
                ->nullOnDelete();

            // Comma-separated employee column names, for kind = `data`.
            // `first_name,last_name,email,phone` rather than a JSON blob:
            // it is a list of identifiers, it never needs querying as JSON,
            // and a comma list is readable in the row at 2 a.m.
            $table->string('employee_fields', 500)->nullable();

            $table->boolean('is_mandatory')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            // active | inactive — retiring a requirement stops it being
            // demanded of new joiners without rewriting history for people
            // already through the door.
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_requirements');
    }
};
