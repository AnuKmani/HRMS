<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The *definitions* of an approval chain, not the approvals themselves.
     *
     * A workflow answers "who has to sign off on this kind of thing?", and
     * nothing in the codebase is allowed to name an approver inline — a
     * service asked to route a leave request looks here. That is what makes
     * "Employee -> Supervisor -> Project Manager -> HR" and "Employee -> HR"
     * two rows of configuration rather than two branches in an if-statement.
     *
     * `subject_type` separates the chains per subject. Leave and overtime are
     * different questions ("am I away?" vs "did I work extra?") and a single
     * default chain for both would force one of them to inherit the other's
     * approval rules.
     *
     * Exactly one workflow per subject_type may carry `is_default`, enforced
     * in the service rather than by a partial unique index — MySQL has no
     * way to say "unique where is_default = 1" on a boolean, and a second
     * unique index over (subject_type, code) already covers the real risk,
     * which is two workflows claiming the same name.
     */
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();

            // LEAVE-STD, OT-STD, … stable and caller-facing: a settings screen
            // and a seeder both refer to a chain by this, never by its row id.
            $table->string('code', 40)->unique();
            $table->string('name', 100);

            // leave | overtime. A string rather than an enum — Phase 2 decided
            // no MySQL ENUMs anywhere, and adding a third subject later should
            // not need a schema change.
            $table->string('subject_type', 30)->index();

            $table->string('description', 500)->nullable();

            // The chain a request falls back to when its leave type does not
            // name one. One per subject_type, chosen by ApprovalWorkflowService.
            $table->boolean('is_default')->default(false);

            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['subject_type', 'code'], 'awf_subject_code_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_workflows');
    }
};
