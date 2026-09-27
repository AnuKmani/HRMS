<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per link in an approval chain, ordered by `sequence`.
     *
     * Three approver kinds, and the reason each exists:
     *
     *   reporting_manager — "my boss". Resolved from the *subject's* employee
     *                      at the moment the chain starts, so a reorganisation
     *                      does not silently re-route a request already in
     *                      flight (the materialised copy lives in
     *                      `approval_records`).
     *   role              — "anybody with this role", e.g. HR Admin.
     *   permission        — "anybody holding this permission", which is how a
     *                      deployment points a step at a permission it invents
     *                      later without editing code.
     *
     * `approver_role` and `approver_permission` are nullable because exactly
     * one of them is meaningful per row; a check constraint would be dead
     * weight here since the service validates the pairing on write anyway.
     */
    public function up(): void
    {
        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('approval_workflow_id')
                ->constrained('approval_workflows')
                ->cascadeOnDelete();

            // 1-based. Gaps are allowed and mean nothing — the service walks
            // the steps in order, it does not assume arithmetic.
            $table->unsignedSmallInteger('sequence');

            // "Supervisor", "Project Manager", "HR" — the human label shown in
            // the timeline, deliberately not derived from approver_role so a
            // step can read "Head of HR" while still resolving to HR Admin.
            $table->string('name', 100);

            $table->string('approver_type', 30);

            $table->string('approver_role', 100)->nullable();
            $table->string('approver_permission', 100)->nullable();

            $table->timestamps();

            $table->unique(
                ['approval_workflow_id', 'sequence'],
                'awsf_workflow_seq_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_workflow_steps');
    }
};
