<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The holiday calendar, in three scopes on one table rather than three.
     *
     *   public — the country's statutory days, no site attached.
     *   company — the whole organisation (foundation day, shutdown week).
     *   site   — a day off at one location only, `site_id` set.
     *
     * One table because LeaveDayCalculator asks one question — "is this date
     * a day off for *this* employee?" — and three tables would turn that into
     * three queries unioned together for no gain. The scope is a column, and
     * the uniqueness of a row follows from (date, type, site_id).
     *
     * A site holiday is not unique-indexed against NULL, because MySQL treats
     * NULLs as distinct in a unique index and would let the same public
     * holiday be inserted twice while appearing to guarantee it cannot be.
     * Deduplication is done in the service with a plain lookup instead — an
     * honest check rather than an index that only half works.
     *
     * Recurrence: deliberately not modelled. A `repeats` column would need a
     * rule engine (which nth weekday, which lunar calendar) before it could
     * be trusted, and a wrong recurrence silently removes a working day from
     * every leave calculation that follows. Holidays are written as concrete
     * dates — simple, inspectable, and correct — and a future phase can add
     * generation on top of rows that already exist.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);

            $table->date('date')->index();

            // public | company | site — a string, for the usual reason: no
            // MySQL ENUMs (Phase 2), and a fourth scope must not need DDL.
            $table->string('type', 20)->default('public')->index();

            $table->foreignId('site_id')
                ->nullable()
                ->constrained('sites')
                ->nullOnDelete();

            $table->string('description', 500)->nullable();

            // Inactive holidays stay in the table — deleting one would erase
            // the reason last year's leave excluded that date.
            $table->string('status', 20)->default('active')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'type'], 'hol_date_type_idx');
            $table->index(['site_id', 'date'], 'hol_site_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
