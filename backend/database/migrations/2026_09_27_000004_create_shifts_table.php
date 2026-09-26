<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Shift times are stored as TIME. A night shift that crosses midnight is
     * flagged with `crosses_midnight` so duration maths never has to guess:
     * duration = (end + 24h) - start when the flag is set.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('code', 30)->unique();

            $table->time('start_time');
            $table->time('end_time');

            // Computed by the Shift model whenever start/end change.
            $table->boolean('crosses_midnight')->default(false)->index();

            $table->unsignedSmallInteger('break_duration')->default(0);      // minutes
            $table->unsignedSmallInteger('grace_period')->default(0);        // minutes
            $table->decimal('minimum_working_hours', 4, 2)->default(8.00);
            $table->decimal('overtime_threshold', 4, 2)->default(0.00);      // hours beyond minimum

            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
