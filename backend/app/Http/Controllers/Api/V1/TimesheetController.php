<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateTimesheetsRequest;
use App\Http\Resources\TimesheetResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Timesheet;
use App\Services\Timesheet\TimesheetService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/timesheets
 *
 * Reads a derived snapshot and, on request, derives one. Nothing in this
 * controller can author a timesheet: every field it returns was copied from
 * an `attendances` row by TimesheetService, and the only write available is
 * `POST /timesheets/generate`, which re-derives a window from attendance.
 *
 * That is the whole design in one sentence — attendance is the source of
 * truth, the timesheet is its materialised projection — and it is why there
 * are no approval endpoints here. See the migration's note: signing a
 * timesheet off would assert nothing that approving the underlying
 * attendance does not, and would put a second, weaker door next to the only
 * one there is.
 */
class TimesheetController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly TimesheetService $timesheets) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        $query = Visibility::timesheetsFor(
            Timesheet::query()->with(['employee', 'project', 'site', 'shift']),
            $request->user(),
        );

        if ($employeeId = $this->param($request, 'employee_id')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($projectId = $this->param($request, 'project_id')) {
            $query->where('project_id', (int) $projectId);
        }

        if ($siteId = $this->param($request, 'site_id')) {
            $query->where('site_id', (int) $siteId);
        }

        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($from = $this->param($request, 'from')) {
            $query->where('timesheet_date', '>=', $from);
        }

        if ($to = $this->param($request, 'to')) {
            $query->where('timesheet_date', '<=', $to);
        }

        $page = $query->orderBy(
            $this->sortColumn(
                $request,
                ['timesheet_date', 'working_minutes', 'overtime_minutes', 'status', 'updated_at'],
                'timesheet_date',
            ),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Timesheets retrieved.',
            TimesheetResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, Timesheet $timesheet): JsonResponse
    {
        $this->authorize('view', $timesheet);

        $timesheet->load(['employee', 'project', 'site', 'shift']);

        return ApiResponse::success('Timesheet retrieved.', new TimesheetResource($timesheet));
    }

    /**
     * POST /api/v1/timesheets/generate
     *
     * Re-derive a window from attendance. Safe to press twice: the target is
     * `updateOrCreate` on (employee, date), so a repeat run refreshes rows
     * rather than duplicating them — which is the whole reason a manager who
     * is not sure whether somebody else already ran it can press it anyway.
     */
    public function generate(GenerateTimesheetsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $written = $this->timesheets->generate(
            $request->user(),
            $data['from'],
            $data['to'],
            isset($data['employee_id']) ? (int) $data['employee_id'] : null,
        );

        return ApiResponse::success(
            sprintf('Derived %d timesheet row(s) from attendance.', $written),
            ['count' => $written],
        );
    }
}
