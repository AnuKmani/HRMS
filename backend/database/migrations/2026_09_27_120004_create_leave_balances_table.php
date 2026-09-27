<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (employee, leave type, calendar year).
     *
     * Five stored numbers, one derived answer:
     *
     *     remaining = entitlement + carry_forward + adjustment - used - pending
     *
     * `remaining` is deliberately NOT a column. Storing it would mean two
     * writers — one moving `used`, one moving `adjustment` — each having to
     * remember to recompute it, and a balance that drifts is worse than no
     * balance at all. LeaveBalance::remaining() is the single formula.
     *
     * `pending` is what separates "approved" from "merely requested". Reserving
     * days when a request is submitted is what stops two overlapping requests
     * from both being told the balance is sufficient, and it is released again
     * on rejection and cancellation. See LeaveBalanceService.
     *
     * `adjustment` is signed: HR correcting last year's entitlement up or down
     * without rewriting history (the change is recorded in `updated_at`, and
     * the dedicated audit phase will attach a reason to it).
     *
     * DECIMAL rather than integer for used/pending because half-days are real
     * — a half-day of sick leave is a thing people take, and rounding it away
     * would silently give time back.
     */
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->foreignId('leave_type_id')
                ->constrained('leave_types')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('year');

            $table->unsignedSmallInteger('entitlement')->default(0);
            $table->unsignedSmallInteger('carry_forward')->default(0);
            $table->integer('adjustment')->default(0);

            $table->decimal('used', 6, 2)->default(0);
            $table->decimal('pending', 6, 2)->default(0);

            $table->timestamps();

            $table->unique(
                ['employee_id', 'leave_type_id', 'year'],
                'lb_emp_type_year_idx',
            );
            $table->index(['year'], 'lb_year_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
