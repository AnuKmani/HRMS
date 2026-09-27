<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeDetailResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Employee;
use App\Services\EmployeeService;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/employees
 *
 * The record everything else in the system points at, so this controller is
 * the one place where three separate concerns have to be kept apart:
 *
 *  - the **coarse gate** — `permission:employees.view|create|update|delete`
 *    on the routes, plus `authorize()` on every method;
 *  - **row scope** — Visibility narrows a list and a single record by the
 *    projects/sites a field role actually runs (config/hrms.php);
 *  - **the sensitive field** — salary is never rendered by EmployeeResource,
 *    and only by EmployeeDetailResource when the caller holds
 *    `employees.salary.view`.
 *
 * `GET /employees/{employee}` is the one route without a `permission:`
 * middleware, deliberately: an Employee may open their own profile while
 * holding no `employees.view` at all. The policy is what decides, and it
 * answers true only for the row that belongs to the caller.
 */
class EmployeeController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $query = Employee::query()->with([
            'department',
            'designation',
            'primaryProject',
            'primarySite',
        ]);

        // Applied after the coarse gate and before any filter: a scope the
        // filters could accidentally bypass is not a scope.
        Visibility::employeesFor($query, $request->user());

        $this->applyFilters($query, $request);

        $query->orderBy(
            $this->sortColumn($request, [
                'employee_code', 'first_name', 'last_name', 'joining_date',
                'employment_status', 'department_id', 'designation_id', 'created_at', 'id',
            ], 'employee_code'),
            $this->sortDirection($request),
        )->orderBy('id');

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Employees retrieved.',
            EmployeeResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        // No `permission:` middleware on this route — see the class note.
        $this->authorize('view', $employee);

        $employee->load([
            'department',
            'designation',
            'reportingManager',
            'primaryProject',
            'primarySite',
            // Full posting history, newest first. Someone allowed to open a
            // person may see where that person has been: it is workforce
            // provenance, not payroll, and it is what makes an assignment
            // chain readable.
            'siteAssignments' => fn ($q) => $q->orderByDesc('start_date')->orderByDesc('id'),
            'siteAssignments.site',
            'siteAssignments.project',
        ]);

        return ApiResponse::success('Employee retrieved.', new EmployeeDetailResource($employee));
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employees->create($request->validated());

        return ApiResponse::created(
            'Employee created.',
            new EmployeeDetailResource($employee->load([
                'department', 'designation', 'reportingManager', 'primaryProject', 'primarySite',
            ])),
        );
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $employee = $this->employees->update($employee, $request->validated());

        return ApiResponse::success(
            'Employee updated.',
            new EmployeeDetailResource($employee->load([
                'department', 'designation', 'reportingManager', 'primaryProject', 'primarySite',
            ])),
        );
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        // Soft delete, always. The row is referenced by assignments,
        // direct reports and primary placements; removing it outright would
        // erase who reported to whom and who stood where. Archived, not
        // destroyed.
        $employee->delete();

        return ApiResponse::success('Employee deleted.');
    }

    /**
     * Read shaping only — nothing here decides what the caller may see,
     * which Visibility and the policies already settled.
     *
     * @param  Builder<Employee>  $query
     */
    private function applyFilters($query, Request $request): void
    {
        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        if ($value = $this->param($request, 'department_id', 'department')) {
            $query->where('department_id', (int) $value);
        }

        if ($value = $this->param($request, 'designation_id', 'designation')) {
            $query->where('designation_id', (int) $value);
        }

        if ($value = $this->param($request, 'employment_status', 'status')) {
            $query->where('employment_status', $value);
        }

        if ($value = $this->param($request, 'employment_type', 'type')) {
            $query->where('employment_type', $value);
        }

        if ($value = $this->param($request, 'reporting_manager_id', 'reporting_manager', 'manager')) {
            $query->where('reporting_manager_id', (int) $value);
        }

        // Project and site each match the primary placement *or* any posting
        // history: "who works on this job" is the question a project manager
        // actually asks, and primary_project_id alone answers it only for
        // people who have not moved since.
        if ($value = $this->param($request, 'project_id', 'project')) {
            $projectId = (int) $value;
            $query->where(function ($q) use ($projectId) {
                $q->where('primary_project_id', $projectId)
                    ->orWhereExists(function ($sub) use ($projectId) {
                        $sub->selectRaw('1')
                            ->from('employee_site_assignments')
                            ->whereColumn('employee_site_assignments.employee_id', 'employees.id')
                            ->where('employee_site_assignments.project_id', $projectId);
                    });
            });
        }

        if ($value = $this->param($request, 'site_id', 'site')) {
            $siteId = (int) $value;
            $query->where(function ($q) use ($siteId) {
                $q->where('primary_site_id', $siteId)
                    ->orWhereExists(function ($sub) use ($siteId) {
                        $sub->selectRaw('1')
                            ->from('employee_site_assignments')
                            ->whereColumn('employee_site_assignments.employee_id', 'employees.id')
                            ->where('employee_site_assignments.site_id', $siteId);
                    });
            });
        }
    }
}
