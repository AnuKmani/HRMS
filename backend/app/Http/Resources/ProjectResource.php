<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Services\Projects\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project.
 *
 * The project manager is rendered through EmployeeResource — the projection
 * with no salary on it — for the reason explained there: this payload is
 * reachable behind `projects.view`, which Management, Finance and every
 * field role holds, and none of them has asked about payroll.
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Project $resource */
        $resource = $this->resource;

        $money = $request->user() !== null && ProjectService::canSeeMoney($request->user(), $resource);

        return [
            'id' => $resource->id,
            'name' => $resource->name,
            'code' => $resource->code,
            'client' => $resource->client,
            'main_contractor' => $resource->main_contractor,
            'description' => $resource->description,
            'location' => $resource->location,

            'project_manager_id' => $resource->project_manager_id,
            'project_manager' => $this->whenLoaded('projectManager', fn () => $resource->projectManager
                ? new EmployeeResource($resource->projectManager)
                : null),

            'start_date' => $resource->start_date?->toDateString(),
            'end_date' => $resource->end_date?->toDateString(),
            'status' => $resource->status,

            // Progress, and how it compares with where it should be by now.
            'progress_percent' => (float) $resource->progress_percent,
            'progress_updated_at' => $resource->progress_updated_at?->toIso8601String(),
            'expected_percent' => $resource->expectedPercent(),
            'behind_schedule' => $resource->isBehindSchedule(),

            // Who added it — the only Project Manager who may edit it.
            'created_by_name' => $this->whenLoaded('creator', fn () => $resource->creator?->name),
            'can_edit' => $request->user() !== null && ProjectService::canEdit($request->user(), $resource),
            'can_coordinate' => $request->user() !== null && ProjectService::canCoordinate($request->user(), $resource),
            'can_reassign' => $request->user() !== null && ProjectService::canReassign($request->user(), $resource),

            'can_see_money' => $money,
            'stages' => $this->whenLoaded('stages', fn () => $resource->stages->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'weight' => (float) $s->weight,
                'percent_complete' => (float) $s->percent_complete,
                'contract_value' => $money && $s->contract_value !== null ? (float) $s->contract_value : null,
                'billed_amount' => $money ? (float) $s->billed_amount : null,
            ])->values()),
            'subcontractors' => $this->whenLoaded('subcontractors', fn () => $resource->subcontractors->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'trade' => $c->trade,
                'contact_name' => $c->contact_name,
                'contact_phone' => $c->contact_phone,
                'contract_value' => $money && $c->contract_value !== null ? (float) $c->contract_value : null,
                'billed_amount' => $money ? (float) $c->billed_amount : null,
                'project_stage_id' => $c->project_stage_id,
                'start_date' => $c->start_date?->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'has_document' => $c->document_path !== null,
                'document_name' => $c->document_name,
                'status' => $c->status,
                'removed_on' => $c->removed_on?->toDateString(),
                'removed_reason' => $c->removed_reason,
            ])->values()),
            'team' => $this->whenLoaded('members', fn () => $resource->members->map(fn ($m) => [
                'employee_id' => $m->employee_id,
                'name' => $m->employee?->full_name,
                'role' => $m->role,
            ])->values()),
            'payment_milestones' => $this->whenLoaded('paymentMilestones', fn () => ProjectMilestoneResource::collection($resource->paymentMilestones)),
            'documents' => $this->whenLoaded('documents', fn () => $resource->documents->map(fn ($d) => [
                'id' => $d->id,
                'title' => $d->title,
                'category' => $d->category,
                'original_name' => $d->original_name,
                'size' => (int) $d->size,
                'uploaded_at' => $d->created_at?->toIso8601String(),
            ])->values()),

            // DCD / Civil Defence / handover dates. `upcoming_events` on the
            // list (today on), `events` with history on a single project.
            'upcoming_events' => $this->whenLoaded('upcomingEvents', fn () => ProjectEventResource::collection($resource->upcomingEvents)),
            'events' => $this->whenLoaded('events', fn () => ProjectEventResource::collection($resource->events)),

            'sites_count' => $this->counted('sites_count'),
            'employees_count' => $this->counted('employees_count'),

            'created_at' => $resource->created_at?->toIso8601String(),
        ];
    }

    private function counted(string $attribute): ?int
    {
        $attributes = $this->resource->getAttributes();

        return array_key_exists($attribute, $attributes) ? (int) $attributes[$attribute] : null;
    }
}
