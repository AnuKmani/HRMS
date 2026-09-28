<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The itemisation behind one payroll row: every earning and every
     * deduction as its own line.
     *
     * The payroll row carries the totals; this table carries the *reasons*.
     * Keeping both is deliberate - a slip that says "deductions 4,120.00"
     * with nothing underneath is not a document anybody can query, and a
     * table holding only the lines would make "what did March cost us?"
     * a full scan of child rows.
     *
     * `type` + `code` are two columns rather than one because they answer
     * different questions. `type` decides the sign (an `earning` adds, a
     * `deduction` subtracts) and is therefore what a totals query groups on;
     * `code` says which rule produced it (`basic`, `allowance`, `overtime`,
     * `bonus`, `lop`, `leave_unpaid`, `loan`, `advance`, `other`) and is
     * what a reader sees on the slip.
     *
     * `source_type` + `source_id` point back at whatever produced the line -
     * an allowance row, an overtime request, a loan installment, an approved
     * adjustment. Nullable because `basic` has no source but the employee's
     * own salary column. They are deliberately *not* a polymorphic FK: MySQL
     * cannot enforce one, so these two columns are documentation and
     * traceability, not a constraint. The integrity that matters - the line
     * summing to the total - is enforced by writing both in one transaction.
     *
     * Rows are **replaced** on recalculation rather than updated in place.
     * A line whose source was deleted or whose rate changed has no correct
     * "update", and diffing old against new would be inventing history the
     * payroll row itself does not keep.
     */
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_id')
                ->constrained('payrolls')
                ->cascadeOnDelete();

            $table->string('type', 20);      // earning | deduction
            $table->string('code', 40);
            $table->string('description', 255);

            // Quantity/rate explain a line rather than being used to
            // re-derive it: `amount` is authoritative, `quantity` x `rate` is
            // what the slip prints so a reader can see *why*. Both nullable
            // because `total_deductions` style lines have neither.
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('rate', 12, 4)->nullable();

            $table->decimal('amount', 12, 2);

            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Only where a line genuinely needs more than the columns above -
            // today, the loan installment number. Not a junk drawer: a field
            // that can hold anything is a field nothing can validate.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['payroll_id', 'type'], 'pi_payroll_type_idx');
            $table->index(['source_type', 'source_id'], 'pi_source_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
