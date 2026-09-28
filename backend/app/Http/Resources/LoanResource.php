<?php

namespace App\Http\Resources;

use App\Models\Loan;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A loan or a salary advance, with its repayment schedule when asked for.
 *
 * The balance, the schedule and the status are all emitted because they
 * answer three different questions a borrower actually asks: "how much do I
 * still owe?" (balance), "when is the next one taken?" (the next pending
 * installment) and "am I still repaying?" (status). None of them is
 * derivable from the others on the client without re-implementing
 * LoanService.
 *
 * `next_installment` is computed here rather than in a screen for the same
 * reason: it depends on which rows are `pending`, and "pending" is
 * LoanService's word, not a filter a widget should be re-deciding.
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Loan $resource */
        $resource = $this->resource;

        // `whenLoaded` is a JsonResource method, not an Eloquent one: the
        // schedule is emitted only when a caller asked for it (the list does
        // not), and asking for it from a model would be a fatal error rather
        // than the deliberate omission the omission is meant to be.
        $installments = $this->whenLoaded('installments');

        $next = $resource->relationLoaded('installments')
            ? $resource->installments
                ->first(fn ($installment) => $installment->status === 'pending')
            : null;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'loan_type' => $resource->loan_type,
            'type_label' => $resource->typeLabel(),
            'reference' => $resource->reference,

            // The currency travels with the figures for the same reason
            // `PayrollResource` sends it: `system.currency` is a setting, and
            // a screen that read it from a constant would print the old code
            // on a loan the moment the organisation changed it.
            'currency' => app(SettingsService::class)->string('system.currency', 'INR'),

            'principal_amount' => $resource->principal_amount,
            'installment_amount' => $resource->installment_amount,
            'number_of_installments' => $resource->number_of_installments,
            'outstanding_balance' => $resource->outstanding_balance,
            'repaid_amount' => number_format(
                (float) $resource->principal_amount - (float) $resource->outstanding_balance,
                2,
                '.',
                '',
            ),

            'start_date' => $resource->start_date?->toDateString(),
            'status' => $resource->status,

            'next_installment' => $next === null
                ? null
                : (object) [
                    'sequence' => $next->sequence,
                    'due_date' => $next->due_date?->toDateString(),
                    'amount' => $next->amount,
                ],

            'remarks' => $resource->remarks,
            'created_by' => $resource->created_by,
            'approved_by' => $resource->approved_by,
            'approved_at' => $resource->approved_at?->toIso8601String(),
            'rejected_at' => $resource->rejected_at?->toIso8601String(),
            'cancelled_at' => $resource->cancelled_at?->toIso8601String(),

            'installments' => $installments,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
