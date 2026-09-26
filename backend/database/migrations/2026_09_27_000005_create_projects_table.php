<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `project_manager_id` is created as a plain indexed column here because
     * the `employees` table does not exist yet (employees.primary_project_id
     * points back at projects). The FK constraint is added afterwards in
     * 2026_09_27_000009_add_deferred_foreign_keys.php to avoid a circular
     * dependency at schema-creation time.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->string('client', 150)->nullable();
            $table->string('description', 1000)->nullable();
            $table->string('location', 500)->nullable();

            $table->unsignedBigInteger('project_manager_id')->nullable()->index();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->string('status', 20)->default('planned')->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
