<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many days a request asked for beyond what was left when it was
 * submitted. HR decides on approval: give the days, or make them loss of
 * pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->decimal('over_balance_days', 6, 2)->default(0)->after('requested_days');
            $table->decimal('available_days_at_submit', 6, 2)->nullable()->after('over_balance_days');
            $table->string('excess_decision', 10)->nullable()->after('available_days_at_submit');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['over_balance_days', 'available_days_at_submit', 'excess_decision']);
        });
    }
};
