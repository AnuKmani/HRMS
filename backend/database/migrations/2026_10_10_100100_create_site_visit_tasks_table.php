<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A planned site visit: a Project Manager asks a field engineer to visit a
 * project (and optionally one of its sites) on a given day, up to a month
 * ahead. Completed by the engineer — automatically when their site visit
 * there ends, or by hand with a note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visit_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('visit_date')->index();
            $table->string('purpose', 150);
            $table->string('notes', 500)->nullable();
            $table->string('status', 20)->default('planned')->index();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('site_visit_id')->nullable()->constrained('site_visits')->nullOnDelete();
            $table->string('completion_note', 500)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visit_tasks');
    }
};
