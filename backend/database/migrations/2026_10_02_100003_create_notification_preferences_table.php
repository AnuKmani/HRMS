<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-person, per-category switches.
     *
     * **An absent row means "enabled".** Preferences are therefore sparse:
     * a user who has never opened the preferences screen has never written
     * a row, and the default answer must not depend on having written one.
     * Turning something off is the only reason a row exists, which also
     * means a newly added category is opted-in for everybody the day it
     * ships rather than silently muted for everybody who had not visited
     * the screen.
     *
     * `category` is a short catalogue key (`leave`, `payroll`,
     * `document_expiry`, …) and the *mandatory* categories are refused at
     * the API, not stored as a flag here — a column saying "this row may
     * not be turned off" would let a future migration turn it off.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('category', 40);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
