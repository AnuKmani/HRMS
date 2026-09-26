<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Two FK constraints could not be declared while their target table was
     * still being created (a circular reference between employees <-> sites
     * and employees <-> projects). Both tables now exist, so add them here.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('primary_site_id')
                ->references('id')
                ->on('sites')
                ->nullOnDelete()
                ->onUpdate('cascade');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('project_manager_id')
                ->references('id')
                ->on('employees')
                ->nullOnDelete()
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['primary_site_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['project_manager_id']);
        });
    }
};
