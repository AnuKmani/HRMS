<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One employee's onboarding — where they are in the process.
     *
     * The row holds *only* the process state. Which requirements exist is
     * onboarding_requirements, and whether each one is met is computed by
     * OnboardingService from the documents, employee fields and bank record
     * that are themselves the evidence. Nothing here duplicates any of
     * that, which is the point: a checklist counter stored alongside the
     * data it counts is a counter that goes stale the moment the data
     * changes without touching it.
     *
     * Four states, in the order an HR desk moves through them:
     *
     *  - `draft`             — opened, nothing collected yet;
     *  - `pending_documents` — the employee has been asked and is uploading;
     *  - `hr_review`         — files are in, HR is verifying them;
     *  - `completed`         — every mandatory requirement is satisfied.
     *
     * `completed` is written only after OnboardingService::unmet() comes
     * back empty; asking for it earlier is a 409 that names what is
     * missing rather than a 403, because "you are not allowed" would be
     * the wrong sentence about a form that is simply unfinished.
     *
     * `employee_id` is unique rather than a plain foreign key: there is one
     * onboarding per person, and the unique index is the cheap guarantee
     * that two simultaneous `PUT`s cannot open two of them.
     *
     * The row does **not** cascade away with the employee. An employee
     * row that has been through onboarding is historical fact, and so is
     * the record of how it went.
     */
    public function up(): void
    {
        Schema::create('employee_onboarding', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->unique()
                ->constrained('employees')
                ->restrictOnDelete();

            // draft | pending_documents | hr_review | completed.
            $table->string('status', 30)->default('draft')->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('notes', 1000)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_onboarding');
    }
};
