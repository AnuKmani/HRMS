<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project's stages double as its systems (HVAC, Electrical, Plumbing…):
 * each may carry its contract value and what the company has billed for
 * it. Installation subcontractors get what they have billed, and which
 * system they work on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            $table->decimal('contract_value', 14, 2)->nullable()->after('weight');
            $table->decimal('billed_amount', 14, 2)->default(0)->after('contract_value');
        });

        Schema::table('project_subcontractors', function (Blueprint $table) {
            $table->decimal('billed_amount', 14, 2)->default(0)->after('contract_value');
            $table->foreignId('project_stage_id')->nullable()->after('project_id')->constrained('project_stages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_subcontractors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_stage_id');
            $table->dropColumn('billed_amount');
        });

        Schema::table('project_stages', function (Blueprint $table) {
            $table->dropColumn(['contract_value', 'billed_amount']);
        });
    }
};
