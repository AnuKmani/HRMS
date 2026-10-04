<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance is a person's day, not a site's: a selfie and a GPS reading,
 * taken wherever they are — office, home office, a place not on the site
 * list. A site is recorded when the check-in happened at one, and is what
 * the Site visit flow is built on; it is no longer required to check in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->unsignedBigInteger('site_id')->nullable()->change();
            $table->unsignedBigInteger('project_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible once a site-less day exists: those rows would have
        // nothing to point at. Leave the columns nullable.
    }
};
