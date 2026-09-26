<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();

            // Nullable: some designations (e.g. "Graduate Trainee") are
            // organisation-wide rather than owned by one department.
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->string('name', 120);
            $table->string('code', 30)->unique();
            $table->string('description', 500)->nullable();
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'status'], 'desig_dept_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
