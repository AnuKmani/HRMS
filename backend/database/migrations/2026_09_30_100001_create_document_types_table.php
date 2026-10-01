<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document types — the configurable half of employee document storage.
     *
     * Nothing about a document kind is written into a service anywhere: is
     * a number expected, is an issue date expected, does it expire, and how
     * far ahead should somebody be told it is about to — are all read from
     * this row by EmployeeDocumentService, DocumentExpiryService and the
     * onboarding checklist alike. Adding "Labour Card" means adding a row,
     * not a deploy, and changing the warning window from 30 to 60 days is a
     * column rather than a search-and-replace across the codebase.
     *
     * `code` is the stable handle a seeder, an integration or a report keys
     * off — names get reworded ("Emirates ID" -> "EID"), codes do not — and
     * the unique index is what lets DocumentTypeSeeder be re-run without
     * duplicating a type it already wrote.
     *
     * The three `requires_*` flags are per-type because one global rule
     * cannot answer all three questions at once: a passport has a number,
     * an issue date and an expiry; a training certificate has none of the
     * first two and often no expiry at all. They drive validation on the
     * document row, which is why they live here rather than in a FormRequest
     * — a rule hard-coded in a request is a rule that cannot be changed by
     * the person who runs the company.
     *
     * `expiry_warning_days` is *this type's* warning window, and it is the
     * number DocumentExpiryService uses to decide what "expiring soon"
     * means for this kind of document. Emirates IDs expire months before
     * passports do in practice, so one global window would either cry wolf
     * or warn late for one of them. Zero falls back to
     * `config('hrms.expiry.default_warning_days')` rather than switching the
     * warning off — a type nobody configured is not a type that never
     * expires.
     */
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('code', 40)->unique();
            $table->string('description', 500)->nullable();

            $table->boolean('requires_document_number')->default(false);
            $table->boolean('requires_issue_date')->default(false);
            $table->boolean('requires_expiry_date')->default(false);

            // Days before `expiry_date` that this kind of document counts as
            // "expiring soon". unsignedSmallInteger because 65 535 days is
            // already two centuries and a decade.
            $table->unsignedSmallInteger('expiry_warning_days')->default(30);

            // active | inactive. An inactive type cannot be chosen for a new
            // document; documents already filed under it stay put, because
            // history must not vanish when a category is retired.
            $table->string('status', 20)->default('active')->index();

            // Ordering for the pick list: the nine seeded types arrive in the
            // order an HR desk expects them, and an operator may reorder.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
