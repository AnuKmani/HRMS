<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only history: an employee moving sites creates a NEW row with a
     * start date and closes the old one with an end date. Nothing is ever
     * overwritten, so every FK here restricts deletion instead of cascading.
     */
    public function up(): void
    {
        Schema::create('employee_site_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();

            $table->foreignId('site_id')
                ->constrained('sites')
                ->restrictOnDelete();

            // primary | temporary | additional
            $table->string('assignment_type', 20)->default('primary')->index();

            $table->date('start_date');
            $table->date('end_date')->nullable();

            // active | ended | cancelled
            $table->string('status', 20)->default('active')->index();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'status'], 'esa_emp_status_idx');
            $table->index(['site_id', 'start_date'], 'esa_site_start_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_site_assignments');
    }
};
