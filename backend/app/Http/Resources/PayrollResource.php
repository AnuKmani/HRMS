<?php

namespace App\Http\Resources;

use App\Models\Payroll;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One month of somebody's pay, in the shape every screen renders - the
 * payroll list, the detail, the admin period view and the salary-slip list
 * all read this same projection, because they are four doors onto one row.
 *
 * Two rules the resource is careful about:
 *
 *  - **money arrives as a string, never as a JSON number.** Every figure is
 *    `decimal:2` on the model, so `"30000.00"` travels as far as it can and
 *    the client parses it deliberately. A JSON `30000.00` becomes a Dart
 *    `double`, which is then re-rounded by whatever formats it next - the
 *    exact UI rounding the spec forbids. Parsing `"30000.00"` and printing
 *    `"30,000.00"` from one formatter is how a screen and a PDF agree.
 *
 *  - **`currency` travels with the figures.** The organisation's currency is
 *    a setting an operator can change, and a client that assumed `INR`
 *    because it was `INR` when the app was written would put the wrong
 *    symbol on a payslip after the company relocated. One field, read from
 *    the same `system.currency` the PDF reads.
 *
 * `items` is opt-in so a list of fifty rows does not issue fifty sequences
 * of queries - the detail screen asks for them, the list does not.
 */
class PayrollResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Payroll $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'payroll_year' => $resource->payroll_year,
            'payroll_month' => $resource->payroll_month,
            'period_label' => $resource->periodLabel(),
            'period_start' => $resource->period_start?->toDateString(),
            'period_end' => $resource->period_end?->toDateString(),

            // --- earnings ------------------------------------------------
            'basic_salary' => $resource->basic_salary,
            'total_allowances' => $resource->total_allowances,
            'overtime_amount' => $resource->overtime_amount,
            'overtime_minutes' => $resource->overtime_minutes,
            'bonus_amount' => $resource->bonus_amount,
            'gross_salary' => $resource->gross_salary,

            // --- unpaid days and deductions ------------------------------
            'lop_days' => $resource->lop_days,
            'lop_divisor' => $resource->lop_divisor,
            'lop_amount' => $resource->lop_amount,
            'loan_deduction' => $resource->loan_deduction,
            'advance_deduction' => $resource->advance_deduction,
            'other_deductions' => $resource->other_deductions,
            'total_deductions' => $resource->total_deductions,
            'net_salary' => $resource->net_salary,

            'status' => $resource->status,

            // --- who moved it, when --------------------------------------
            'reviewed_at' => $resource->reviewed_at?->toIso8601String(),
            'reviewed_by' => $resource->reviewed_by,
            'processed_at' => $resource->processed_at?->toIso8601String(),
            'processed_by' => $resource->processed_by,
            'locked_at' => $resource->locked_at?->toIso8601String(),
            'locked_by' => $resource->locked_by,

            // A draft row exists because there was nothing to calculate -
            // see PayrollCalculation::blockedReason(). Surfacing the reason
            // rather than making a screen infer it from a zero is the whole
            // difference between "no pay" and "no salary on record".
            'blocked_reason' => $resource->status === Payroll::STATUS_DRAFT
                ? 'This employee has no salary on record, so there is nothing to calculate.'
                : null,

            // May the caller still change this? Server-decided so the client
            // never has to duplicate the status table - and so a button that
            // should be hidden is hidden for the right reason rather than
            // because a copy of the rules happened to agree today.
            'can_recalculate' => ! $resource->isImmutable(),
            'can_lock' => $resource->status === Payroll::STATUS_PROCESSED,

            'currency' => app(SettingsService::class)->string('system.currency', 'INR'),

            'items' => $this->whenLoaded('items', fn () => PayrollItemResource::collection($resource->items)),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
