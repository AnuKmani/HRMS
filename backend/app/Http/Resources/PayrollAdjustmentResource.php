<?php

namespace App\Http\Resources;

use App\Models\PayrollAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A one-off bonus, other deduction or signed adjustment.
 *
 * `amount` is the stored figure and `signed_amount` is what it does to pay -
 * the pair, never one of them alone. For a bonus they are equal; for an
 * `other_deduction` they differ in sign; for an `adjustment` they are equal
 * because the amount already carries its own direction. Emitting both means
 * a client that displays "amount" next to a plus or minus cannot pick the
 * wrong one, and the sign decision stays in PayrollAdjustment::signedAmount()
 * where there is exactly one of it.
 *
 * `payable` says whether a pay run would pick this row up - a single answer
 * to "has this been signed off?" rather than a status comparison repeated in
 * every screen.
 */
class PayrollAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PayrollAdjustment $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'payroll_year' => $resource->payroll_year,
            'payroll_month' => $resource->payroll_month,
            'period_label' => $resource->payroll_month.' / '.$resource->payroll_year,

            'type' => $resource->type,
            'description' => $resource->description,
            'amount' => $resource->amount,
            'signed_amount' => number_format($resource->signedAmount(), 2, '.', ''),

            'status' => $resource->status,
            'payable' => in_array($resource->status, PayrollAdjustment::PAYABLE, true),

            'approved_by' => $resource->approved_by,
            'approved_at' => $resource->approved_at?->toIso8601String(),
            'rejected_at' => $resource->rejected_at?->toIso8601String(),
            'cancelled_at' => $resource->cancelled_at?->toIso8601String(),
            'remarks' => $resource->remarks,
            'created_by' => $resource->created_by,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
