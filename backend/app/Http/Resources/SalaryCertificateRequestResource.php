<?php

namespace App\Http\Resources;

use App\Models\SalaryCertificateRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request for a salary certificate, and the decision on it.
 *
 * `reference` is derived rather than stored (see
 * SalaryCertificateRequest::reference()), so it is emitted here as a plain
 * fact: the number HR will quote in correspondence exists the moment the row
 * exists, not the moment the PDF is first rendered.
 *
 * `can_issue` is the server's answer to "is there a document to download?",
 * combining permission and row visibility with the row's own state - so a
 * button appears only when pressing it will produce something, and the state
 * rule lives in one place instead of in the resource, the controller and the
 * screen.
 */
class SalaryCertificateRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SalaryCertificateRequest $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'reference' => $resource->reference(),
            'request_date' => $resource->request_date?->toDateString(),
            'purpose' => $resource->purpose,

            'status' => $resource->status,
            'can_issue' => $resource->isIssuable(),

            'approved_by' => $resource->approved_by,
            'approved_at' => $resource->approved_at?->toIso8601String(),
            'rejected_at' => $resource->rejected_at?->toIso8601String(),
            'cancelled_at' => $resource->cancelled_at?->toIso8601String(),
            'generated_at' => $resource->generated_at?->toIso8601String(),

            'remarks' => $resource->remarks,
            'created_by' => $resource->created_by,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
