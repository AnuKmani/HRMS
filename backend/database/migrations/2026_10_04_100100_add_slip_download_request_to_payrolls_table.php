<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee asks for their payslip; HR releases it.
 *
 * One request per payroll row, so the state lives on the row itself rather
 * than in a table of its own: null (never asked), requested, approved or
 * rejected, with who decided and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->string('slip_request_status', 20)->nullable()->index();
            $table->timestamp('slip_requested_at')->nullable();
            $table->timestamp('slip_decided_at')->nullable();
            $table->foreignId('slip_decided_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('slip_decided_by');
            $table->dropColumn(['slip_request_status', 'slip_requested_at', 'slip_decided_at']);
        });
    }
};
