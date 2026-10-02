<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurable training programs — the catalogue HR maintains.
     *
     * Everything that could have been a constant is a column: the provider,
     * the duration, whether a certificate is required and how long that
     * certificate lasts. A deployment whose first-aid card is good for three
     * years edits one row instead of forking a service.
     *
     * **`certificate_validity_days` is days, not months, and that is a
     * deliberate reduction of the "days/months" the brief offers.** Two units
     * on one column would mean a conversion somewhere, and every conversion
     * is a place where "12 months" quietly becomes 360 days. Months are
     * expressed as days in the row (365, 360, 90); one unit, one meaning,
     * and a `addDays()` on completion rather than a calendar calculation
     * that disagrees with itself across February.
     *
     * `certificate_required` drives completion: a program that requires one
     * cannot be marked complete without an issue date or a file, which is
     * asked of the service rather than of the screen so a 409/422 says the
     * same thing whichever client sent the request.
     *
     * `duration_days` rather than a free-text `duration` because it is the
     * part of a program a report sorts and filters on; the *shape* of the
     * session ("2 days", "half day") belongs in `description`.
     */
    public function up(): void
    {
        Schema::create('training_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_type_id')
                ->constrained('training_types')
                ->restrictOnDelete();

            $table->string('code', 60)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();

            // The default provider/trainer for the program. An individual
            // enrolment may name a different one (see employee_trainings),
            // which is the difference between "our HSE course" and "the one
            // Gulf Safety ran for us in April".
            $table->string('provider', 200)->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();

            $table->boolean('certificate_required')->default(false);
            $table->unsignedSmallInteger('certificate_validity_days')->nullable();

            $table->string('status', 20)->default('active')->index();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_programs');
    }
};
