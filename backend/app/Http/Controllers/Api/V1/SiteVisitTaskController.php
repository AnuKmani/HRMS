<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteVisitTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Site;
use App\Models\SiteVisitTask;
use App\Models\User;
use App\Services\Attendance\SiteVisitTaskService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * /api/v1/site-visit-plans
 *
 * A Project Manager plans site visits for field engineers, up to a month
 * ahead; each engineer sees theirs on the dashboard; a plan is completed
 * by the engineer (or automatically when their site visit there ends) and
 * both sides see it completed.
 *
 * Who sees what: an engineer, the plans assigned to them; a planner, the
 * plans they made (and their own); an unscoped administrator, all.
 */
class SiteVisitTaskController extends Controller
{
    public function __construct(private readonly SiteVisitTaskService $tasks) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = SiteVisitTask::query()->with(['project', 'site', 'employee', 'assigner']);

        // A Project Manager is scoped on people: they see the plans they
        // made and their own, never another manager's. Only an unscoped
        // administrator sees every plan.
        $seesAll = $user->can('site_visit_plans.manage') && ! Visibility::employeesAreScopedFor($user);

        if (! $seesAll) {
            $employeeId = $user->employee?->id ?? 0;
            $query->where(function ($q) use ($user, $employeeId) {
                $q->where('employee_id', $employeeId)->orWhere('assigned_by', $user->id);
            });
        }

        if ($request->query('scope') === 'mine') {
            $query->where('employee_id', $user->employee?->id ?? 0);
        }

        $status = (string) $request->query('status', '');
        $today = now()->toDateString();
        match ($status) {
            'upcoming' => $query->where('status', SiteVisitTask::STATUS_PLANNED)->whereDate('visit_date', '>=', $today),
            'missed' => $query->where('status', SiteVisitTask::STATUS_PLANNED)->whereDate('visit_date', '<', $today),
            'completed' => $query->where('status', SiteVisitTask::STATUS_COMPLETED),
            'cancelled' => $query->where('status', SiteVisitTask::STATUS_CANCELLED),
            default => null,
        };

        $rows = $query
            ->orderByRaw("case when status = 'planned' then 0 else 1 end")
            ->orderBy('visit_date')
            ->orderBy('id')
            ->limit(200)
            ->get();

        return ApiResponse::success('Site visit plans.', [
            'items' => SiteVisitTaskResource::collection($rows),
        ]);
    }

    /**
     * POST — one plan per engineer listed, same project, place and day.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = now()->toDateString();
        $last = now()->addDays(SiteVisitTask::MAX_DAYS_AHEAD)->toDateString();

        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:50'],
            'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'visit_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today, 'before_or_equal:'.$last],
            'purpose' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'visit_date.after_or_equal' => 'The visit date cannot be in the past.',
            'visit_date.before_or_equal' => 'Visits can be planned up to '.SiteVisitTask::MAX_DAYS_AHEAD.' days ahead.',
        ]);

        // Any project this planner may see — a Project Manager sees them
        // all, and engineers are sent across projects.
        $project = Project::query()->findOrFail($data['project_id']);
        if (! Visibility::projectIsVisible($user, $project)) {
            abort(403, 'You cannot plan visits for that project.');
        }

        if (! empty($data['site_id'])) {
            $site = Site::query()->findOrFail($data['site_id']);
            if ((int) $site->project_id !== (int) $project->id) {
                throw ValidationException::withMessages(['site_id' => 'That site is not part of the chosen project.']);
            }
        }

        $allowed = $this->assigneeIds();
        $invalid = array_diff($data['employee_ids'], $allowed);
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'employee_ids' => 'Only people with the Site visit option (site engineers, supervisors, officers, technicians, project managers) can be given a site visit.',
            ]);
        }

        $created = DB::transaction(fn () => collect($data['employee_ids'])->map(fn (int $employeeId) => SiteVisitTask::query()->create([
            'project_id' => $project->id,
            'site_id' => $data['site_id'] ?? null,
            'employee_id' => $employeeId,
            'assigned_by' => $user->id,
            'visit_date' => $data['visit_date'],
            'purpose' => $data['purpose'],
            'notes' => $data['notes'] ?? null,
            'status' => SiteVisitTask::STATUS_PLANNED,
        ])));

        $created->each(fn (SiteVisitTask $task) => $this->tasks->notifyAssigned($task));
        $created->each->load(['project', 'site', 'employee', 'assigner']);

        return ApiResponse::created(
            $created->count() === 1 ? 'Site visit planned.' : $created->count().' site visits planned.',
            ['items' => SiteVisitTaskResource::collection($created)],
        );
    }

    /**
     * POST /{task}/complete — the engineer says it is done.
     */
    public function complete(Request $request, SiteVisitTask $siteVisitTask): JsonResponse
    {
        $user = $request->user();

        if ((int) ($user->employee?->id ?? 0) !== (int) $siteVisitTask->employee_id) {
            abort(403, 'Only the engineer the visit was planned for can complete it.');
        }

        if ($siteVisitTask->status !== SiteVisitTask::STATUS_PLANNED) {
            abort(409, 'This visit is not open any more.');
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $task = $this->tasks->complete($siteVisitTask, null, $data['note'] ?? null);
        $task->load(['project', 'site', 'employee', 'assigner']);

        return ApiResponse::success('Marked as completed.', new SiteVisitTaskResource($task));
    }

    /**
     * POST /{task}/cancel — the planner withdraws a visit not yet done.
     */
    public function cancel(Request $request, SiteVisitTask $siteVisitTask): JsonResponse
    {
        $user = $request->user();
        $mayAll = ! Visibility::employeesAreScopedFor($user);

        if ((int) $siteVisitTask->assigned_by !== (int) $user->id && ! $mayAll) {
            abort(403, 'Only the person who planned this visit can cancel it.');
        }

        if ($siteVisitTask->status !== SiteVisitTask::STATUS_PLANNED) {
            abort(409, 'Only a planned visit can be cancelled.');
        }

        $siteVisitTask->forceFill(['status' => SiteVisitTask::STATUS_CANCELLED])->save();
        $siteVisitTask->load(['project', 'site', 'employee', 'assigner']);

        return ApiResponse::success('Visit cancelled.', new SiteVisitTaskResource($siteVisitTask));
    }

    /**
     * GET /assignees — the people a visit can be planned for: everyone with
     * the Site visit option.
     */
    public function assignees(): JsonResponse
    {
        $employees = Employee::query()
            ->whereIn('id', $this->assigneeIds())
            ->where('employment_status', Employee::STATUS_ACTIVE)
            ->with('user.roles')
            ->get()
            ->sortBy(fn (Employee $e) => $e->full_name)
            ->values()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->full_name,
                'role' => $e->user?->roles->pluck('name')->first(),
            ]);

        return ApiResponse::success('People who can be given a site visit.', ['items' => $employees]);
    }

    /**
     * @return array<int, int>
     */
    private function assigneeIds(): array
    {
        $userIds = User::permission('site_visits.create')->pluck('id');

        return Employee::query()->whereIn('user_id', $userIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
