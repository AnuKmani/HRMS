<?php

namespace App\Http\Resources;

use App\Models\AssetType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A kind of company asset — the vocabulary, not an item.
 *
 * Same shape and same reasoning as TrainingTypeResource: four columns, no
 * person, no policy to ask. This is what a form's picker reads.
 *
 * `status` ships because a retired type still appears beside the assets
 * filed under it — a laptop registered under a kind that has since been
 * withdrawn still has to be recognisable as the kind it was registered as.
 */
class AssetTypeResource extends JsonResource
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
            'is_active' => $this->status === AssetType::STATUS_ACTIVE,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
