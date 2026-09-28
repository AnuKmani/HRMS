<?php

namespace App\Http\Resources;

use App\Models\PayrollItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line on a payslip.
 *
 * `quantity` and `rate` are emitted beside `amount` because they are what
 * makes a line *explainable* - "6 days x 1,000.00" is a figure a person can
 * check against their leave history, where "6,000.00" alone is a number they
 * have to take on faith. `amount` remains authoritative: the other two are
 * the working shown, not a formula anything recomputes from.
 *
 * `source_type` and `source_id` are surfaced as a small `source` object so
 * "where did this come from?" is answered on the detail screen without a
 * round-trip - the identifier is worthless to a client that cannot name it,
 * so the type is spelled out rather than left as a class path.
 */
class PayrollItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PayrollItem $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'payroll_id' => $resource->payroll_id,

            'type' => $resource->type,
            'code' => $resource->code,
            'description' => $resource->description,

            'quantity' => $resource->quantity,
            'rate' => $resource->rate,
            'amount' => $resource->amount,

            'source' => $resource->source_type === null
                ? null
                : (object) [
                    'type' => $resource->source_type,
                    'id' => $resource->source_id,
                ],

            'metadata' => $resource->metadata,
        ];
    }
}
