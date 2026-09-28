<?php

namespace App\Http\Resources;

use App\Models\Allowance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One employee's allowance.
 *
 * The window and the frequency travel as data rather than as a client-side
 * guess, because `appliesTo()` decides both and a screen that re-derived
 * "does this pay in March?" from two dates would have a second answer to a
 * question with one.
 *
 * `applies_now` is that same rule evaluated server-side for the current
 * period - a convenience flag, not an authorization one, and the only place
 * the rule is asked outside PayrollCalculationService.
 */
class AllowanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Allowance $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'code' => $resource->code,
            'label' => $resource->label,
            'amount' => $resource->amount,

            'frequency' => $resource->frequency,
            'effective_from' => $resource->effective_from?->toDateString(),
            'effective_to' => $resource->effective_to?->toDateString(),
            'payroll_year' => $resource->payroll_year,
            'payroll_month' => $resource->payroll_month,

            'status' => $resource->status,
            'remarks' => $resource->remarks,
            'created_by' => $resource->created_by,

            'applies_now' => $resource->appliesTo(
                (int) now()->year,
                (int) now()->month,
                now()->startOfMonth()->toDateString(),
            ),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
