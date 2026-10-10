<?php

namespace App\Http\Resources;

use App\Models\ProjectPaymentMilestone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMilestoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectPaymentMilestone $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'name' => $m->name,
            'trigger_percent' => (float) $m->trigger_percent,
            'amount_percent' => $m->amount_percent !== null ? (float) $m->amount_percent : null,
            'amount' => $m->amount !== null ? (float) $m->amount : null,
            'status' => $m->status,
            'reached_at' => $m->reached_at?->toIso8601String(),
            'requested_at' => $m->requested_at?->toIso8601String(),
            'paid_at' => $m->paid_at?->toIso8601String(),
            'notes' => $m->notes,
        ];
    }
}
