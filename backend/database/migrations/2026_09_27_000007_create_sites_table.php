<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Coordinates and geofence radius are columns — never hard-coded constants.
     * Latitude/longitude use DECIMAL(10,7) (~1.1 cm precision) rather than
     * FLOAT to avoid binary rounding error at geofence boundaries.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->restrictOnDelete();   // never silently orphan/drop a site

            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->string('address', 500)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('geofence_radius', 8, 2)->nullable();   // metres

            $table->foreignId('site_manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->foreignId('site_supervisor_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            // Working-hours configuration reference (a settings row), not a
            // hard-coded copy of daily hours.
            $table->foreignId('working_hours_setting_id')
                ->nullable()
                ->constrained('settings')
                ->nullOnDelete();

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('shifts')
                ->nullOnDelete();

            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status'], 'site_project_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
