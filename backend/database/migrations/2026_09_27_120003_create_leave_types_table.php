<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable leave types — the whole reason no leave rule is hard-coded.
     *
     * Every number a leave request is judged against lives on this row: how
     * many days a person gets, whether they can carry any over, the cap on a
     * single request, whether a doctor's note is compulsory and how long they
     * have to produce one. Changing Sick Leave from 3 days to 5 is an UPDATE,
     * not a deploy.
     *
     * `entitlement_days` is per calendar year and is the *base* — carry-forward
     * and manual adjustments are separate columns on `leave_balances`, because
     * folding them into this number would mean the entitlement changed every
     * time somebody's balance moved.
     *
     * `maximum_days_per_request` of 0 means "no cap": an explicit zero beats a
     * nullable column here, because "unset" and "nobody may ever take a day"
     * are different rules and only one of them should be expressible.
     *
     * `document_deadline_days` of 0 means "fall back to the
     * `leave.sick_certificate_deadline_days` setting" — the leave type wins
     * when it has an opinion, the organisation's default answers otherwise.
     *
     * `allow_negative_balance` is the explicit opt-out for B's rule that a
     * balance may never go below zero. Unpaid Leave sets it: there is no
     * entitlement to exhaust, so counting "remaining" against zero would
     * refuse every request by construction.
     */
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->string('description', 500)->nullable();

            // Days available per year.
            $table->unsignedSmallInteger('entitlement_days')->default(0);

            $table->boolean('carry_forward_enabled')->default(false);
            $table->unsignedSmallInteger('carry_forward_limit')->default(0);

            $table->unsignedSmallInteger('maximum_days_per_request')->default(0);

            // Whether the days cost pay — read by payroll later, and by the
            // balance service today to decide whether an approved request
            // actually consumes anything.
            $table->boolean('is_paid')->default(true);

            // Sick-leave medical certificate workflow.
            $table->boolean('requires_document')->default(false);
            $table->unsignedSmallInteger('document_deadline_days')->default(0);

            $table->boolean('allow_negative_balance')->default(false);

            $table->string('status', 20)->default('active')->index();

            // Null = "use the default chain for leave". Explicit = "this type
            // has its own route to sign-off".
            $table->foreignId('approval_workflow_id')
                ->nullable()
                ->constrained('approval_workflows')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
