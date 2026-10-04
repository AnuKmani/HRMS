<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the employee is on site today, said at the moment of check-in.
 *
 * Its own column rather than `notes`: `notes` is HR's field for explaining
 * an adjustment, and an employee's own words should never be overwritten
 * by — or mistaken for — a correction somebody else made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('check_in_purpose', 150)->nullable()->after('check_in_selfie_path');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('check_in_purpose');
        });
    }
};
