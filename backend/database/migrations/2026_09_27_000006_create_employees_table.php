<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Normalised employee master. Sensitive documents are deliberately NOT
     * stored here — they live in their own table with private storage paths
     * (Phase 10).
     *
     * `primary_site_id` is created as a plain column because `sites` does not
     * exist yet (sites.site_manager_id points back at employees). Its FK is
     * added in 2026_09_27_000009_add_deferred_foreign_keys.php.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Optional link: an employee may exist before a login is issued.
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('employee_code', 30)->unique();

            // Structured names — sortable, and usable for legal documents.
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);

            $table->string('photo_path', 500)->nullable();

            $table->string('email', 190)->unique();
            $table->string('phone', 30)->nullable();

            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('address', 500)->nullable();

            // Emergency contact
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->string('emergency_contact_relation', 60)->nullable();

            $table->date('joining_date');

            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->foreignId('designation_id')
                ->nullable()
                ->constrained('designations')
                ->nullOnDelete();

            // permanent | contract | probation | internship | part_time
            $table->string('employment_type', 30)->default('permanent')->index();

            // Self-referencing reporting line.
            $table->foreignId('reporting_manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->foreignId('primary_project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();

            // Deferred FK — sites table is created next.
            $table->unsignedBigInteger('primary_site_id')->nullable()->index();

            // DECIMAL never FLOAT for money.
            $table->decimal('salary', 12, 2)->nullable();

            // active | inactive | resigned | terminated | on_leave
            $table->string('employment_status', 20)->default('active')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'employment_status'], 'emp_dept_status_idx');
            $table->index('joining_date', 'emp_joining_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
