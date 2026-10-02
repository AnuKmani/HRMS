<?php

namespace App\Http\Resources;

use App\Models\TrainingType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A kind of training — the vocabulary, not an offering.
 *
 * The payload is four columns on purpose: nothing here describes a person
 * or a cohort, so there is nothing to narrow and no policy to ask. It is
 * the list a form's picker reads, and the list is the same for everybody
 * who may open the training screen at all.
 *
 * `status` ships because a retired type still appears beside the courses
 * that were filed under it — an operator has to be able to tell "this is
 * not offered any more" from "this never existed" when reading a cohort
 * from last year.
 */
class TrainingTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'is_active' => $this->status === TrainingType::STATUS_ACTIVE,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
