<?php

namespace App\Http\Resources;

use App\Models\LoanInstallment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One scheduled repayment, with what has actually been taken of it.
 *
 * `payroll_id` and `deducted_at` travel together and mean the same thing:
 * an installment is only ever deducted *by* a payroll row, and the pointer
 * is the most recent one that did. Emitting both lets a screen say "taken
 * by March's run on the 31st" without a second lookup, and lets a test
 * assert the pairing the schema cannot enforce.
 *
 * The three money figures are deliberately all present rather than two of
 * them being derivable client-side, because they answer different
 * questions and none of them is a function of the others without knowing
 * what `loans.outstanding_balance` is:
 *
 *   `amount`           what the schedule says is due
 *   `deducted_amount`  what runs have actually taken of it, so far
 *   `remaining_amount` what is still owed on this installment
 *
 * With a partial deduction, `status` is `partially_deducted` and
 * `remaining_amount` is what the next run will be offered first.
 */
class LoanInstallmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LoanInstallment $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'loan_id' => $resource->loan_id,

            'sequence' => $resource->sequence,
            'due_date' => $resource->due_date?->toDateString(),
            'amount' => $resource->amount,

            'deducted_amount' => $resource->deducted_amount,
            'remaining_amount' => Money::decimal($resource->remainingAmount()),

            'status' => $resource->status,
            'payroll_id' => $resource->payroll_id,
            'deducted_at' => $resource->deducted_at?->toIso8601String(),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
