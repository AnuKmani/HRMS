<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeSiteAssignmentRequest;
use App\Http\Requests\UpdateEmployeeSiteAssignmentRequest;
use App\Http\Resources\EmployeeSiteAssignmentResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\EmployeeSiteAssignment;
use App\Models\Site;
use App\Services\EmployeeSiteAssignmentService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/employee-site-assignments
 *
 * There is no `destroy` here and there will not be one: the table is the
 * organisation's memory of where people were, and `DELETE` is how that
 * memory gets rewritten. The only mutation offered is a close — `status` and
 * `end_date` — which UpdateEmployeeSiteAssignmentRequest refuses to let
 * anything else touch.
 *
 * `GET .../{assignment}` carries no `permission:` middleware for the same
 * reason `GET /employees/{employee}` does not: an employee may read their own
 * posting while holding no `assignments.view` at all, and the policy is what
 * separates "yours" from "somebody else's".
 */
class EmployeeSiteAssignmentController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly EmployeeSiteAssignmentService $assignments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeSiteAssignment::class);

        $query = EmployeeSiteAssignment::query()->with([
            'employee',
            'project',
            'site',
        ]);

        Visibility::assignmentsFor($query, $request->user());

        if ($value = $this->param($request, 'employee_id', 'employee')) {
            $query->where('employee_id', (int) $value);
        }

        if ($value = $this->param($request, 'project_id', 'project')) {
            $query->where('project_id', (int) $value);
        }

        if ($value = $this->param($request, 'site_id', 'site')) {
            $query->where('site_id', (int) $value);
        }

        if ($value = $this->param($request, 'status')) {
            $query->where('status', $value);
        }

        if ($value = $this->param($request, 'assignment_type', 'type')) {
            $query->where('assignment_type', $value);
        }

        // History reads newest-first by default: the question is almost
        // always "where are they now", and the answer is the first row.
        $query->orderBy(
            $this->sortColumn($request, ['start_date', 'end_date', 'status', 'created_at', 'id'], 'start_date'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id');

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Assignments retrieved.',
            EmployeeSiteAssignmentResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(EmployeeSiteAssignment $assignment): JsonResponse
    {
        // No coarse middleware — see the class note. The policy decides.
        $this->authorize('view', $assignment);

        $assignment->load(['employee', 'project', 'site']);

        return ApiResponse::success('Assignment retrieved.', new EmployeeSiteAssignmentResource($assignment));
    }

    public function store(StoreEmployeeSiteAssignmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        // `assignments.manage` is a coarse gate; a Site Supervisor holds it
        // and is still only allowed to post people to sites they run. The
        // form request cannot know that — it has no row to check against
        // until now.
        $site = Site::query()->findOrFail($data['site_id']);

        if (! Visibility::siteIsVisible($request->user(), $site)) {
            abort(403, 'You do not have permission to assign employees to this site.');
        }

        $assignment = $this->assignments->create($data, $request->user());
        $assignment->load(['employee', 'project', 'site']);

        return ApiResponse::created('Assignment created.', new EmployeeSiteAssignmentResource($assignment));
    }

    public function update(UpdateEmployeeSiteAssignmentRequest $request, EmployeeSiteAssignment $assignment): JsonResponse
    {
        // Row-level first, so a scoped caller cannot close a posting on a
        // site they do not run even though they hold assignments.manage.
        $this->authorize('update', $assignment);

        $assignment = $this->assignments->close($assignment, $request->validated());
        $assignment->load(['employee', 'project', 'site']);

        return ApiResponse::success('Assignment closed.', new EmployeeSiteAssignmentResource($assignment));
    }
}
