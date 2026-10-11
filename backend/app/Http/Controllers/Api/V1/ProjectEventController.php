<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\ProjectEvent;
use App\Services\Projects\ProjectService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST/PUT/DELETE /api/v1/projects/{project}/events[/{projectEvent}]
 *
 * The dates HR watches on the project list — DCD inspection, Civil Defence
 * inspection, handover. Written by whoever may edit the project (the
 * Project Manager running it); read by everyone who may see the project.
 */
class ProjectEventController extends Controller
{
    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizeCoordinate($project);

        $event = $project->events()->create($this->validated($request) + [
            'created_by' => $request->user()->id,
        ]);

        return ApiResponse::created('Event added.', new ProjectEventResource($event));
    }

    public function update(Request $request, Project $project, ProjectEvent $projectEvent): JsonResponse
    {
        $this->authorizeCoordinate($project);
        $this->belongs($project, $projectEvent);

        $projectEvent->update($this->validated($request));

        return ApiResponse::success('Event updated.', new ProjectEventResource($projectEvent->refresh()));
    }

    public function destroy(Project $project, ProjectEvent $projectEvent): JsonResponse
    {
        $this->authorizeCoordinate($project);
        $this->belongs($project, $projectEvent);

        $projectEvent->delete();

        return ApiResponse::success('Event removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(ProjectEvent::TYPES)],
            'title' => ['nullable', 'string', 'max:150'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** The project's editor, or a Project Coordinator assigned to it. */
    private function authorizeCoordinate(Project $project): void
    {
        $user = request()->user();

        if (! Visibility::projectIsVisible($user, $project) || ! ProjectService::canCoordinate($user, $project)) {
            abort(403, 'You cannot change this project.');
        }
    }

    private function belongs(Project $project, ProjectEvent $event): void
    {
        if ((int) $event->project_id !== (int) $project->id) {
            abort(404);
        }
    }
}
