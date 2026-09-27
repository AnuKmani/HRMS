<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckInRequest;
use App\Http\Requests\StoreCheckOutRequest;
use App\Http\Resources\AttendanceResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Attendance;
use App\Services\Attendance\AttendanceService;
use App\Services\Attendance\SelfieStore;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/attendance
 *
 * Two of these routes carry no `permission:` middleware, deliberately:
 *
 *   GET  /attendance/today   — the employee's own front door;
 *   POST /attendance/check-in, /check-out — recording your own day is not
 *          a privilege anybody grants you.
 *
 * The policy is what separates "yours" from "everybody else's" on each of
 * them, and it fails closed: `GET /attendance` (which DOES carry
 * `permission:attendance.view`) still narrows to the caller's own rows
 * unless they are trusted with the workforce and scoped to what they run.
 *
 * Nothing here computes anything. Distance, lateness, working time and
 * status all come back from AttendanceService already decided — a controller
 * that did arithmetic would be a second place for the rules to live.
 */
class AttendanceController extends Controller
{
    use BuildsResourceLists;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly SelfieStore $selfies,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);

        $query = Attendance::query()->with(['employee', 'site', 'project', 'shift']);

        // Row scope first, so every filter below is applied to the rows the
        // caller may see rather than narrowing a set they were never
        // allowed to read in the first place.
        Visibility::attendanceFor($query, $request->user());

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

        if ($value = $this->param($request, 'date', 'attendance_date')) {
            $query->whereDate('attendance_date', $value);
        }

        // Inclusive range. `from`/`to` are accepted as aliases because a
        // caller writing a report reaches for those names first.
        if ($value = $this->param($request, 'date_from', 'from')) {
            $query->whereDate('attendance_date', '>=', $value);
        }

        if ($value = $this->param($request, 'date_to', 'to')) {
            $query->whereDate('attendance_date', '<=', $value);
        }

        $query->orderBy(
            $this->sortColumn($request, ['attendance_date', 'check_in_at', 'check_out_at', 'status', 'created_at', 'id'], 'attendance_date'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id');

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Attendance retrieved.',
            AttendanceResource::collection($page->getCollection()),
            $page,
        );
    }

    /**
     * The employee's own day, ready to render.
     *
     * No `permission:` middleware — see the class note.
     */
    public function today(Request $request): JsonResponse
    {
        $this->authorize('today', Attendance::class);

        return ApiResponse::success('Today retrieved.', $this->attendance->today($request->user()));
    }

    public function checkIn(StoreCheckInRequest $request): JsonResponse
    {
        $attendance = $this->attendance->checkIn($request->user(), $request->validated());
        $attendance->load(['site.project', 'project', 'shift']);

        return ApiResponse::created('Checked in.', new AttendanceResource($attendance));
    }

    public function checkOut(StoreCheckOutRequest $request): JsonResponse
    {
        $attendance = $this->attendance->checkOut($request->user(), $request->validated());
        $attendance->load(['site.project', 'project', 'shift']);

        return ApiResponse::success('Checked out.', new AttendanceResource($attendance));
    }

    public function show(Request $request, Attendance $attendance): JsonResponse
    {
        // No coarse middleware: the policy decides whether this row is the
        // caller's own or one they are scoped to.
        $this->authorize('view', $attendance);

        $attendance->load(['employee', 'site', 'project', 'shift']);

        return ApiResponse::success('Attendance retrieved.', new AttendanceResource($attendance));
    }

    /**
     * GET /api/v1/attendance/{attendance}/selfie
     *
     * The only route to a stored photograph, and it is deliberately a
     * request that has to name a row first: you cannot list selfies, you
     * cannot guess a path, and you cannot fetch one for a record you could
     * not otherwise read. The response is `no-store` so a shared device's
     * browser cache does not hand it to whoever picks the phone up next.
     */
    public function selfie(Request $request, Attendance $attendance): StreamedResponse
    {
        $this->authorize('viewSelfie', $attendance);

        $path = $attendance->check_in_selfie_path;

        if ($path === null) {
            abort(404, 'There is no selfie attached to this attendance record.');
        }

        $response = $this->selfies->response($path);

        if ($response === null) {
            // The row survives, the file did not. Say so honestly rather
            // than pretending the record never had one.
            abort(404, 'That selfie is no longer available.');
        }

        return $response;
    }
}
