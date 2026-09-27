<?php

namespace App\Http\Resources;

use App\Models\ApprovalWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workflow definition with its steps, in sequence.
 *
 * Steps are always emitted ordered by `sequence` rather than by id: the order
 * *is* the contract, and a client that sorted them by insertion would draw
 * HR before the supervisor for a chain whose whole point is the opposite.
 */
class ApprovalWorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApprovalWorkflow $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'code' => $resource->code,
            'subject_type' => $resource->subject_type,
            'description' => $resource->description,
            'is_default' => $resource->is_default,
            'status' => $resource->status,

            'steps' => $this->whenLoaded(
                'steps',
                fn () => ApprovalWorkflowStepResource::collection(
                    $resource->steps->sortBy('sequence')->values(),
                ),
            ),

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
