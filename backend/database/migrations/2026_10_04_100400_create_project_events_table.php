<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dated milestones on a project that HR and management watch for: the DCD
 * inspection, the Civil Defence inspection, the handover. Kept as rows,
 * not columns, because a project has several and each can be re-booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('title', 150)->nullable();
            $table->date('event_date')->index();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_events');
    }
};
