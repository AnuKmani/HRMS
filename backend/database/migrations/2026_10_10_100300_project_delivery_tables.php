<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Running a project: who builds it (main contractor, subcontractors), who
 * works on it (site engineers / supervisors), how far it has got (weighted
 * stages, with every change kept), when to bill (payment milestones), and
 * its paperwork (documents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // The Project Manager who added it — the only one who edits it.
            $table->foreignId('created_by')->nullable()->after('project_manager_id')->constrained('users')->nullOnDelete();
            $table->string('main_contractor', 150)->nullable()->after('client');
            $table->decimal('progress_percent', 5, 2)->default(0)->after('status');
            $table->timestamp('progress_updated_at')->nullable()->after('progress_percent');
        });

        Schema::create('project_subcontractors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('trade', 100)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->decimal('contract_value', 14, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name', 190)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->date('removed_on')->nullable();
            $table->string('removed_reason', 500)->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('role', 30); // site_engineer | site_supervisor
            $table->timestamps();
            $table->unique(['project_id', 'employee_id']);
        });

        Schema::create('project_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->decimal('weight', 6, 2);
            $table->decimal('percent_complete', 5, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('project_progress_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('project_stage_id')->nullable()->constrained('project_stages')->nullOnDelete();
            $table->decimal('stage_percent', 5, 2)->nullable();
            $table->decimal('project_percent', 5, 2);
            $table->string('source', 20); // daily_report | manual
            $table->foreignId('daily_site_report_id')->nullable()->constrained('daily_site_reports')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('daily_site_report_stage_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_site_report_id')->constrained('daily_site_reports')->cascadeOnDelete();
            $table->foreignId('project_stage_id')->constrained('project_stages')->cascadeOnDelete();
            $table->decimal('percent', 5, 2);
            $table->unique(['daily_site_report_id', 'project_stage_id'], 'dsr_stage_unique');
        });

        Schema::create('project_payment_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 150);
            $table->decimal('trigger_percent', 5, 2);
            $table->decimal('amount_percent', 5, 2)->nullable(); // share of the contract value
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending|reached|requested|paid
            $table->timestamp('reached_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('project_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('category', 30)->default('other'); // drawing|contract|permit|other
            $table->string('path');
            $table->string('original_name', 190);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_documents');
        Schema::dropIfExists('project_payment_milestones');
        Schema::dropIfExists('daily_site_report_stage_progress');
        Schema::dropIfExists('project_progress_updates');
        Schema::dropIfExists('project_stages');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('project_subcontractors');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['main_contractor', 'progress_percent', 'progress_updated_at']);
        });
    }
};
