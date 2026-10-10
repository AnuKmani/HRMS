<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectMilestoneResource;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectMember;
use App\Models\ProjectPaymentMilestone;
use App\Models\ProjectProgressUpdate;
use App\Models\ProjectStage;
use App\Models\ProjectSubcontractor;
use App\Models\Site;
use App\Models\User;
use App\Services\Projects\ProjectProgressPdf;
use App\Services\Projects\ProjectProgressService;
use App\Services\Projects\ProjectService;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Running a project once it exists: subcontractors, team, stages and
 * progress, payment milestones, documents — and the read-only progress view
 * Sales gets.
 *
 * Changes are for whoever may edit the project (its creator, or Super
 * Admin). Reads follow the project's own visibility; the progress endpoints
 * are the only part Sales may reach.
 */
class ProjectDeliveryController extends Controller
{
    private const DISK = 'local';

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectProgressService $progress,
    ) {}

    // ---- Subcontractors -------------------------------------------------

    public function storeSubcontractor(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $row = $project->subcontractors()->create(
            $this->projects->subcontractorFields($this->subcontractorRules($request)) + ['status' => ProjectSubcontractor::STATUS_ACTIVE],
        );

        return ApiResponse::created('Subcontractor added.', $this->subcontractor($row));
    }

    public function updateSubcontractor(Request $request, Project $project, ProjectSubcontractor $subcontractor): JsonResponse
    {
        $this->authorize('update', $project);
        $this->belongs($project, $subcontractor);

        $subcontractor->update($this->projects->subcontractorFields($this->subcontractorRules($request)));

        return ApiResponse::success('Subcontractor updated.', $this->subcontractor($subcontractor->refresh()));
    }

    /** Never deleted: removed with a reason and date, kept in history. */
    public function removeSubcontractor(Request $request, Project $project, ProjectSubcontractor $subcontractor): JsonResponse
    {
        $this->authorize('update', $project);
        $this->belongs($project, $subcontractor);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'removed_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($subcontractor->status === ProjectSubcontractor::STATUS_REMOVED) {
            abort(409, 'This subcontractor has already been removed.');
        }

        $subcontractor->forceFill([
            'status' => ProjectSubcontractor::STATUS_REMOVED,
            'removed_on' => $data['removed_on'] ?? now()->toDateString(),
            'removed_reason' => $data['reason'],
            'removed_by' => $request->user()->id,
        ])->save();

        return ApiResponse::success('Subcontractor removed. They stay in the project history.', $this->subcontractor($subcontractor));
    }

    public function restoreSubcontractor(Request $request, Project $project, ProjectSubcontractor $subcontractor): JsonResponse
    {
        $this->authorize('update', $project);
        $this->belongs($project, $subcontractor);

        $subcontractor->forceFill([
            'status' => ProjectSubcontractor::STATUS_ACTIVE,
            'removed_on' => null,
            'removed_reason' => null,
            'removed_by' => null,
        ])->save();

        return ApiResponse::success('Subcontractor added back.', $this->subcontractor($subcontractor));
    }

    /** Contract or LPO for one subcontractor (Project Manager only). */
    public function uploadSubcontractorDocument(Request $request, Project $project, ProjectSubcontractor $subcontractor): JsonResponse
    {
        $this->authorize('update', $project);
        $this->belongs($project, $subcontractor);

        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx']]);

        $file = $request->file('file');
        $path = $file->store("project-documents/{$project->id}/subcontractors", self::DISK);

        if ($subcontractor->document_path) {
            Storage::disk(self::DISK)->delete($subcontractor->document_path);
        }

        $subcontractor->forceFill([
            'document_path' => $path,
            'document_name' => mb_substr($file->getClientOriginalName(), 0, 200),
        ])->save();

        return ApiResponse::success('Document attached.', $this->subcontractor($subcontractor));
    }

    public function downloadSubcontractorDocument(Request $request, Project $project, ProjectSubcontractor $subcontractor): Response
    {
        $this->authorize('update', $project);
        $this->belongs($project, $subcontractor);

        if (! $subcontractor->document_path || ! Storage::disk(self::DISK)->exists($subcontractor->document_path)) {
            abort(404, 'No document is attached.');
        }

        return Storage::disk(self::DISK)->download($subcontractor->document_path, $subcontractor->document_name ?: 'document');
    }

    // ---- Team -------------------------------------------------------------

    public function updateTeam(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'site_engineer_ids' => ['present', 'array', 'max:100'],
            'site_engineer_ids.*' => ['integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'site_supervisor_ids' => ['present', 'array', 'max:100'],
            'site_supervisor_ids.*' => ['integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
        ]);

        $this->projects->replaceTeam($project, $data['site_engineer_ids'], $data['site_supervisor_ids']);

        return ApiResponse::success('Team updated.', $this->team($project));
    }

    /** Who can be put on a project team: everyone who writes daily reports. */
    public function teamCandidates(): JsonResponse
    {
        $userIds = User::permission('daily_site_reports.create')->where('status', User::STATUS_ACTIVE)->pluck('id');

        $items = Employee::query()
            ->whereIn('user_id', $userIds)
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

        return ApiResponse::success('People who can join a project team.', ['items' => $items]);
    }

    // ---- Stages and progress -------------------------------------------

    /** Stages of a project — for the daily report form and the progress tab. */
    public function stages(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        // Whoever writes the daily report for one of its sites needs the
        // stage list, even when they cannot open the project itself.
        $reportsHere = $user->can('daily_site_reports.create')
            && Site::query()->where('project_id', $project->id)->whereIn('id', Visibility::attachedSiteIds($user))->exists();

        if (! $reportsHere) {
            $this->authorizeProgress($user, $project);
        }

        return ApiResponse::success('Stages retrieved.', $this->stageList($project));
    }

    public function replaceStages(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'stages' => ['required', 'array', 'min:1', 'max:30'],
            'stages.*.id' => ['nullable', 'integer'],
            'stages.*.name' => ['required', 'string', 'max:100'],
            'stages.*.weight' => ['required', 'numeric', 'min:0.5', 'max:100'],
        ]);

        $total = round(array_sum(array_map(fn ($s) => (float) $s['weight'], $data['stages'])), 2);
        if (abs($total - 100) > 0.01) {
            throw ValidationException::withMessages(['stages' => "Stage weights must add up to 100 (now {$total})."]);
        }

        $this->projects->replaceStages($project, $data['stages']);
        $this->progress->recompute($project);

        return ApiResponse::success('Stages saved.', $this->stageList($project));
    }

    /** The Project Manager corrects a stage by hand. */
    public function correctStage(Request $request, Project $project, ProjectStage $stage): JsonResponse
    {
        $this->authorize('update', $project);
        $this->belongs($project, $stage);

        $data = $request->validate([
            'percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->progress->correct($project, $stage, (float) $data['percent'], $request->user(), $data['note'] ?? null);

        return ApiResponse::success('Progress corrected.', $this->progressSummary($project->refresh()));
    }

    public function history(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProgress($request->user(), $project);

        $rows = ProjectProgressUpdate::query()
            ->with(['stage', 'user'])
            ->where('project_id', $project->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (ProjectProgressUpdate $u) => [
                'id' => $u->id,
                'stage' => $u->stage?->name,
                'stage_percent' => (float) $u->stage_percent,
                'project_percent' => (float) $u->project_percent,
                'source' => $u->source,
                'daily_site_report_id' => $u->daily_site_report_id,
                'by' => $u->user?->name,
                'note' => $u->note,
                'at' => $u->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success('Progress history retrieved.', $rows);
    }

    // ---- Sales: read-only progress ------------------------------------

    public function progressIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Project::query()->orderBy('name');

        if (! $user->can('projects.progress.view') && ! $user->can('projects.view')) {
            abort(403, 'You do not have access to project progress.');
        }

        Visibility::projectsAndSitesFor($query, $user);

        if ($term = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
        }

        return ApiResponse::success('Project progress retrieved.', $query->limit(500)->get()->map(
            fn (Project $p) => $this->progressSummary($p, false),
        ));
    }

    public function progressShow(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProgress($request->user(), $project);

        return ApiResponse::success('Project progress retrieved.', $this->progressSummary($project));
    }

    public function progressPdf(Request $request, Project $project): Response
    {
        $this->authorizeProgress($request->user(), $project);

        return app(ProjectProgressPdf::class)->response($project);
    }

    // ---- Payment milestones -------------------------------------------

    public function milestones(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProgress($request->user(), $project);

        return ApiResponse::success('Milestones retrieved.', ProjectMilestoneResource::collection($project->paymentMilestones()->get()));
    }

    public function storeMilestone(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $milestone = $project->paymentMilestones()->create($this->milestoneRules($request) + [
            'status' => ProjectPaymentMilestone::STATUS_PENDING,
            'sort_order' => (int) $project->paymentMilestones()->max('sort_order') + 1,
        ]);

        // Already past it? Tell Sales now.
        $this->progress->recompute($project);

        return ApiResponse::created('Milestone added.', new ProjectMilestoneResource($milestone->refresh()));
    }

    public function updateMilestone(Request $request, Project $project, ProjectPaymentMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        $this->belongs($project, $milestone);

        $milestone->update($this->milestoneRules($request));
        $this->progress->recompute($project);

        return ApiResponse::success('Milestone updated.', new ProjectMilestoneResource($milestone->refresh()));
    }

    public function destroyMilestone(Project $project, ProjectPaymentMilestone $milestone): JsonResponse
    {
        $this->authorize('update', $project);
        $this->belongs($project, $milestone);

        if (in_array($milestone->status, [ProjectPaymentMilestone::STATUS_REQUESTED, ProjectPaymentMilestone::STATUS_PAID], true)) {
            abort(409, 'A milestone that has been invoiced cannot be removed.');
        }

        $milestone->delete();

        return ApiResponse::success('Milestone removed.');
    }

    /** Sales marks the payment requested, then paid. */
    public function milestoneStatus(Request $request, Project $project, ProjectPaymentMilestone $milestone): JsonResponse
    {
        $this->authorizeProgress($request->user(), $project);

        if (! $request->user()->can('projects.billing.manage')) {
            abort(403, 'Only Sales marks payments requested or paid.');
        }

        $this->belongs($project, $milestone);

        $data = $request->validate([
            'status' => ['required', Rule::in([ProjectPaymentMilestone::STATUS_REQUESTED, ProjectPaymentMilestone::STATUS_PAID])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($milestone->status === ProjectPaymentMilestone::STATUS_PENDING) {
            abort(409, 'This milestone has not been reached yet.');
        }

        $paid = $data['status'] === ProjectPaymentMilestone::STATUS_PAID;

        $milestone->forceFill([
            'status' => $data['status'],
            'requested_at' => $paid ? ($milestone->requested_at ?? now()) : now(),
            'paid_at' => $paid ? now() : null,
            'notes' => $data['notes'] ?? $milestone->notes,
        ])->save();

        return ApiResponse::success('Milestone updated.', new ProjectMilestoneResource($milestone->refresh()));
    }

    // ---- Documents ----------------------------------------------------

    public function documents(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return ApiResponse::success('Documents retrieved.', $project->documents()->latest()->get()->map(fn (ProjectDocument $d) => $this->document($d)));
    }

    public function storeDocument(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(ProjectDocument::CATEGORIES)],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,dwg,dxf,doc,docx,xls,xlsx,zip'],
        ]);

        $file = $request->file('file');

        $document = $project->documents()->create([
            'title' => $data['title'],
            'category' => $data['category'],
            'path' => $file->store("project-documents/{$project->id}", self::DISK),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return ApiResponse::created('Document uploaded.', $this->document($document));
    }

    public function downloadDocument(Request $request, Project $project, ProjectDocument $document): Response
    {
        $this->authorize('view', $project);
        $this->belongs($project, $document);

        if (! Storage::disk(self::DISK)->exists($document->path)) {
            abort(404, 'That file is no longer available.');
        }

        return Storage::disk(self::DISK)->download($document->path, $document->original_name ?: 'document');
    }

    public function destroyDocument(Project $project, ProjectDocument $document): JsonResponse
    {
        $this->authorize('update', $project);
        $this->belongs($project, $document);

        Storage::disk(self::DISK)->delete($document->path);
        $document->delete();

        return ApiResponse::success('Document removed.');
    }

    // ---- Helpers ------------------------------------------------------

    /** Progress is for whoever sees the project, and for Sales. */
    private function authorizeProgress(User $user, Project $project): void
    {
        $sees = $user->can('projects.progress.view') || $user->can('projects.view');

        if (! $sees || ! Visibility::projectIsVisible($user, $project)) {
            abort(403, 'You do not have access to this project.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function progressSummary(Project $project, bool $withStages = true): array
    {
        $summary = [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'status' => $project->status,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'progress_percent' => (float) $project->progress_percent,
            'progress_updated_at' => $project->progress_updated_at?->toIso8601String(),
            'expected_percent' => $project->expectedPercent(),
            'behind_schedule' => $project->isBehindSchedule(),
            'milestones_due' => $project->paymentMilestones()->where('status', ProjectPaymentMilestone::STATUS_REACHED)->count(),
        ];

        if ($withStages) {
            $summary['stages'] = $this->stageList($project);
            $summary['payment_milestones'] = ProjectMilestoneResource::collection($project->paymentMilestones()->get());
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stageList(Project $project): array
    {
        return $project->stages()->get()->map(fn (ProjectStage $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'weight' => (float) $s->weight,
            'percent_complete' => (float) $s->percent_complete,
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function team(Project $project): array
    {
        return ProjectMember::query()->with('employee')->where('project_id', $project->id)->get()
            ->map(fn (ProjectMember $m) => ['employee_id' => $m->employee_id, 'name' => $m->employee?->full_name, 'role' => $m->role])
            ->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function subcontractor(ProjectSubcontractor $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'trade' => $c->trade,
            'contact_name' => $c->contact_name,
            'contact_phone' => $c->contact_phone,
            'contract_value' => $c->contract_value !== null ? (float) $c->contract_value : null,
            'start_date' => $c->start_date?->toDateString(),
            'end_date' => $c->end_date?->toDateString(),
            'has_document' => $c->document_path !== null,
            'document_name' => $c->document_name,
            'status' => $c->status,
            'removed_on' => $c->removed_on?->toDateString(),
            'removed_reason' => $c->removed_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(ProjectDocument $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'category' => $d->category,
            'original_name' => $d->original_name,
            'size' => (int) $d->size,
            'uploaded_at' => $d->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subcontractorRules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'trade' => ['nullable', 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function milestoneRules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'trigger_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'amount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function belongs(Project $project, object $child): void
    {
        if ((int) $child->project_id !== (int) $project->id) {
            abort(404);
        }
    }
}
