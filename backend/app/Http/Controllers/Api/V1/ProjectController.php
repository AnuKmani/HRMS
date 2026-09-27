<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Project;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/projects
 *
 * Visibility is decided in config/hrms.php: a Project Manager holds
 * `projects.manage` and therefore reads every project, while a Site
 * Supervisor holds only `projects.view` and reads the projects that own a
 * site they run. The narrowing happens here *and* on every single record, so
 * the list can never be the easier way round the rule.
 */
class ProjectController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $query = Project::query()
            ->with('projectManager')
            ->withCount(['sites', 'employees']);

        Visibility::projectsAndSitesFor($query, $request->user());

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('client', 'like', $like);
            });
        }

        if ($value = $this->param($request, 'client')) {
            $query->where('client', 'like', '%'.$this->escapeLike($value).'%');
        }

        if ($value = $this->param($request, 'status')) {
            $query->where('status', $value);
        }

        if ($value = $this->param($request, 'project_manager_id', 'project_manager', 'manager')) {
            $query->where('project_manager_id', (int) $value);
        }

        if ($value = $this->param($request, 'start_from')) {
            $query->whereDate('start_date', '>=', $value);
        }

        if ($value = $this->param($request, 'start_to')) {
            $query->whereDate('start_date', '<=', $value);
        }

        if ($value = $this->param($request, 'end_from')) {
            $query->whereDate('end_date', '>=', $value);
        }

        if ($value = $this->param($request, 'end_to')) {
            $query->whereDate('end_date', '<=', $value);
        }

        $query->orderBy(
            $this->sortColumn($request, ['name', 'code', 'client', 'start_date', 'end_date', 'status', 'created_at', 'id'], 'start_date'),
            $this->sortDirection($request),
        )->orderBy('id');

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Projects retrieved.',
            ProjectResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $project->load('projectManager')->loadCount(['sites', 'employees']);

        return ApiResponse::success('Project retrieved.', new ProjectResource($project));
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = Project::create($request->validated());

        return ApiResponse::created(
            'Project created.',
            new ProjectResource($project->load('projectManager')->loadCount(['sites', 'employees'])),
        );
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->update($request->validated());

        return ApiResponse::success(
            'Project updated.',
            new ProjectResource($project->refresh()->load('projectManager')->loadCount(['sites', 'employees'])),
        );
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        // The database would refuse this anyway — sites.project_id is
        // restrictOnDelete — but only after MariaDB has thrown an exception
        // with a constraint name in it. Asking the question first turns that
        // into one sentence a person can act on.
        if ($project->sites()->exists()) {
            return ApiResponse::error(
                'This project still has sites. Remove or reassign them first.',
                ['project_id' => 'Project still has sites.'],
                422,
            );
        }

        $project->delete();

        return ApiResponse::success('Project deleted.');
    }
}
