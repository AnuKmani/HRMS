<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account flag that is independent of employment status.
     *
     * employees.employment_status answers "does this person still work here?"
     * (resigned, terminated, ...). users.status answers "may this login still
     * be used?" — an HR admin can suspend a compromised account without
     * touching the HR record, and can re-enable it later.
     *
     * Kept out of the base users table so the Phase 1 authentication scaffold
     * stays untouched; added here as part of the authentication foundation.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('password')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
