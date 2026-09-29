<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much of a scheduled installment has actually been taken.
     *
     * Until this column existed a repayment was all-or-nothing: a run either
     * claimed the whole installment or left it alone, so the only way to stop
     * an installment pushing net salary below zero was to refuse the run -
     * which silently stopped *every* repayment for that person, or worse,
     * let the net go negative.
     *
     * Three different numbers now have three different homes, and none of
     * them is derivable from another on a screen:
     *
     *  - `amount`              what the schedule says is due (unchanged)
     *  - `deducted_amount`     what pay runs have actually taken, so far
     *  - `amount - deducted_amount` what is still outstanding on this row
     *  - `loans.outstanding_balance` what is left on the loan as a whole
     *
     * Why a column rather than a second table: a partial deduction is a
     * property of one row that one payroll wrote - there is no second
     * entity to model, and a `loan_installment_claims` table would be a
     * join on every read of the schedule to answer "how much is left of
     * installment 3?". The per-run half of the same fact is already
     * recorded where it must be, on `payroll_items`: the deduction line
     * this run wrote, with `source_type = loan_installment` and an amount
     * equal to exactly what was taken. `LoanService::releaseInstallments()`
     * reads those lines rather than a claim column, so releasing one month
     * never disturbs another month's share of the same installment.
     *
     * The backfill below keeps rows written before this migration honest:
     * every installment already marked `deducted` was taken in full, and
     * `deducted_amount = 0` would make it look untouched and therefore
     * deductible a second time.
     */
    public function up(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->decimal('deducted_amount', 12, 2)
                ->default(0)
                ->after('amount');
        });

        DB::table('loan_installments')
            ->where('status', 'deducted')
            ->update(['deducted_amount' => DB::raw('amount')]);
    }

    /**
     * Reverse the migrations - dropping the column loses the partial
     * history, which is why the forward direction is additive and the
     * statuses it wrote (`partially_deducted`) are the only new vocabulary.
     */
    public function down(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->dropColumn('deducted_amount');
        });
    }
};
