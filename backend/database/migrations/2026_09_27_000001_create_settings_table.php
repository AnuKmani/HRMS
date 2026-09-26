<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A key/value settings store so business rules (grace period, overtime
     * threshold, sick-cert deadline, notification timing, default working
     * hours) live in the database instead of being hard-coded in the app.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // Dotted, namespaced key: "attendance.grace_period_minutes".
            $table->string('key', 120)->unique();

            // Stored as text; cast/typed accessors live on the Setting model.
            $table->text('value')->nullable();

            // string | integer | boolean | decimal | json | date | time
            $table->string('type', 20)->default('string');

            // Grouping for the settings UI: attendance, leave, notification, working_hours, ...
            $table->string('group', 40)->default('general')->index();

            $table->string('label', 150);
            $table->string('description', 500)->nullable();

            // Some settings are read-only (system-owned defaults).
            $table->boolean('is_editable')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
