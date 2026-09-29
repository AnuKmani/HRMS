<?php

namespace App\Http\Resources;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One expense category, and the two rules a claim has to satisfy.
 *
 * The whole reason this is a table rather than a switch statement: a form
 * reads `requires_receipt` and `maximum_amount` from here so the screen can
 * warn before submit, and ExpenseService reads the same two values on
 * create, update and submit so the API enforces them regardless of what the
 * screen believed. One source, two readers, no chance of the two disagreeing
 * about whether a taxi fare needs paper.
 *
 * Inactive categories are filtered from the pickable list by the caller;
 * they still appear here on their own so history reads back correctly.
 */
class ExpenseCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ExpenseCategory $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'code' => $resource->code,
            'description' => $resource->description,
            'status' => $resource->status,
            'is_active' => $resource->isActive(),

            'requires_receipt' => $resource->requires_receipt,

            // Null means "no ceiling", which is not the same as 0.
            'maximum_amount' => $resource->maximum_amount,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
