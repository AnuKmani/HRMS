<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recurring and one-time allowances, per employee.
     *
     * The alternative considered and rejected: columns on `payrolls` for
     * housing / transport / food / site. That works until the company adds a
     * fifth allowance, at which point a migration stands between HR and a
     * pay run - and it can never express "this employee's transport allowance
     * changed in July" without losing what it was in June. Here the change is
     * an UPDATE on a row with `effective_from`, and the pay run asks one
     * question: *what was this person entitled to in this period?*
     *
     * Two shapes, distinguished by `frequency` rather than by a nullable
     * column pair:
     *
     *  - `monthly` - applies to every period it is effective for;
     *  - `one_time` - applies to exactly one, named by `payroll_year` +
     *    `payroll_month`. A signing bonus and a transport allowance are the
     *    same object with a different `frequency`, so the calculation reads
     *    one query with one set of rules.
     *
     * `code` is the machine name (`housing`, `transport`, `food`, `site`,
     * `other`) and `label` is what the slip prints. Keeping both means a
     * report can group by `code` without parsing a human sentence, and HR can
     * still rename "Site allowance" to "Remote site allowance" for one
     * employee without breaking the grouping.
     *
     * Soft deletes, matching the other master tables: a deleted allowance
     * must disappear from the picker while rows that already consumed it
     * keep their own frozen `payroll_items` lines.
     */
    public function up(): void
    {
        Schema::create('allowances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();

            $table->string('code', 40);
            $table->string('label', 120);

            $table->decimal('amount', 12, 2);

            $table->string('frequency', 20)->default('monthly');  // monthly | one_time

            // Effective window. `effective_from` defaults to today rather than
            // being required, because "from now" is the overwhelming case and
            // a nullable pair means "no boundary" rather than "unknown".
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            // Set only for `one_time`, null only for `monthly`.
            $table->unsignedSmallInteger('payroll_year')->nullable();
            $table->unsignedTinyInteger('payroll_month')->nullable();

            $table->string('status', 20)->default('active');   // active | cancelled

            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status'], 'alw_emp_status_idx');
            $table->index(['payroll_year', 'payroll_month'], 'alw_period_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('allowances');
    }
};
