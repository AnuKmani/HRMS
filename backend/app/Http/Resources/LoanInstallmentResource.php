<?php

namespace App\Http\Resources;

use App\Models\LoanInstallment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One due repayment.
 *
 * `payroll_id` and `deducted_at` travel together and mean the same thing:
 * an installment is only ever deducted *by* a payroll row, and the pointer
 * is what a recalculation uses to give it back. Emitting both lets a screen
 * say "taken by March's run on the 31st" without a second lookup, and lets a
 * test assert the pairing the schema cannot enforce.
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

            'status' => $resource->status,
            'payroll_id' => $resource->payroll_id,
            'deducted_at' => $resource->deducted_at?->toIso8601String(),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
