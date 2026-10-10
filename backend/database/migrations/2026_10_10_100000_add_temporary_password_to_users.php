<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The password HR handed out, encrypted with the app key, so HR can read it
 * back from the employee's profile until the employee replaces it. Cleared
 * the moment they choose their own: a password the employee picked is
 * never readable by anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('temporary_password')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temporary_password');
        });
    }
};
