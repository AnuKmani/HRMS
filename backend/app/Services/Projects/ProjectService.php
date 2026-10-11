<?php

namespace App\Services\Projects;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectStage;
use App\Models\ProjectSubcontractor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adding and changing a project with everything that belongs to it.
 */
class ProjectService
{
    /** The rule both the request and the policy use. */
    public static function normalisedName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    }

    public static function duplicateOf(string $name, ?int $ignoreId = null): ?Project
    {
        $normal = self::normalisedName($name);

        return Project::query()
            ->with(['projectManager', 'creator'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get(['id', 'name', 'code', 'project_manager_id', 'created_by'])
            ->first(fn (Project $p) => self::normalisedName($p->name) === $normal);
    }

    /** Only its creator edits a project; Super Admin (manage_all) too. */
    public static function canEdit(User $user, Project $project): bool
    {
        if (! $user->can('projects.manage')) {
            return false;
        }

        if ($user->can('projects.manage_all')) {
            return true;
        }

        if ($project->created_by !== null && (int) $project->created_by === (int) $user->id) {
            return true;
        }

        // A Senior Project Manager edits the projects run by the Project
        // Managers who report to them.
        if ($user->can('projects.manage_team') && $user->employee !== null && $project->project_manager_id !== null
            && (int) Employee::query()->whereKey($project->project_manager_id)->value('reporting_manager_id') === (int) $user->employee->id) {
            return true;
        }

        if ($project->created_by !== null) {
            return false;
        }

        // Projects from before creators were recorded: their manager.
        return $user->employee !== null
            && (int) $project->project_manager_id === (int) $user->employee->id;
    }

    /**
     * May keep the project's paperwork: whoever may edit it, or a Project
     * Coordinator assigned to it.
     */
    public static function canCoordinate(User $user, Project $project): bool
    {
        if (self::canEdit($user, $project)) {
            return true;
        }

        return $user->can('projects.coordinate') && $user->employee !== null
            && ProjectMember::query()
                ->where('project_id', $project->id)
                ->where('employee_id', $user->employee->id)
                ->where('role', ProjectMember::ROLE_COORDINATOR)
                ->exists();
    }

    /** Values, contract amounts and billing of this project. */
    public static function canSeeMoney(User $user, Project $project): bool
    {
        return $user->can('projects.dashboard.view')
            || $user->can('projects.billing.manage')
            || self::canEdit($user, $project);
    }

    /** May move the project to another Project Manager. */
    public static function canReassign(User $user, Project $project): bool
    {
        if ($user->can('projects.manage_all')) {
            return true;
        }

        if (! $user->can('projects.reassign')) {
            return false;
        }

        // Operations: any project. Senior PM: the ones their team runs.
        return ! $user->can('projects.manage_team') || self::canEdit($user, $project);
    }

    /**
     * Every system has a value above zero, so weights follow from values.
     *
     * @param  array<int, array<string, mixed>>  $stages
     */
    public static function weightsFromValues(array $stages): bool
    {
        if ($stages === []) {
            return false;
        }

        foreach ($stages as $row) {
            if (! isset($row['contract_value']) || (float) $row['contract_value'] <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Weights as each system's share of the project value (adding up to
     * exactly 100), or the weights as given.
     *
     * @param  array<int, array<string, mixed>>  $stages
     * @return array<int, array<string, mixed>>
     */
    public static function withWeights(array $stages): array
    {
        if (! self::weightsFromValues($stages)) {
            return $stages;
        }

        $total = array_sum(array_map(fn ($s) => (float) $s['contract_value'], $stages));
        $given = 0.0;
        $last = count($stages) - 1;

        foreach ($stages as $i => $row) {
            $weight = $i === $last
                ? round(100 - $given, 2)
                : round((float) $row['contract_value'] / $total * 100, 2);
            $stages[$i]['weight'] = $weight;
            $given += $weight;
        }

        return $stages;
    }

    public function nextCode(): string
    {
        $highest = Project::withTrashed()
            ->where('code', 'like', 'PRJ-%')
            ->pluck('code')
            ->map(fn (string $code) => preg_match('/^PRJ-(\d+)$/', $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return 'PRJ-'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Project
    {
        return DB::transaction(function () use ($user, $data) {
            $project = new Project;
            $project->fill(collect($data)->only([
                'name', 'code', 'client', 'main_contractor', 'description', 'location',
                'project_manager_id', 'start_date', 'end_date', 'status',
            ])->all());
            $project->code = blank($project->code) ? $this->nextCode() : $project->code;
            $project->created_by = $user->id;
            $project->project_manager_id ??= $user->employee?->id;
            $project->status ??= Project::STATUS_ACTIVE;
            $project->save();

            $this->replaceStages($project, $data['stages'] ?? ProjectStage::TEMPLATE);

            foreach ($data['subcontractors'] ?? [] as $row) {
                $project->subcontractors()->create($this->subcontractorFields($row) + [
                    'status' => ProjectSubcontractor::STATUS_ACTIVE,
                ]);
            }

            $this->replaceTeam($project, $data['site_engineer_ids'] ?? [], $data['site_supervisor_ids'] ?? [], $data['coordinator_ids'] ?? []);

            return $project;
        });
    }

    /**
     * Replace the stage list, keeping completion for stages that stay
     * (matched by id).
     *
     * @param  array<int, array<string, mixed>>  $stages
     */
    public function replaceStages(Project $project, array $stages): void
    {
        $keep = [];
        $stages = self::withWeights(array_values($stages));

        foreach ($stages as $i => $row) {
            $stage = isset($row['id'])
                ? ProjectStage::query()->where('project_id', $project->id)->find($row['id'])
                : null;

            $stage ??= new ProjectStage(['project_id' => $project->id, 'percent_complete' => 0]);
            $stage->fill([
                'name' => trim((string) $row['name']),
                'weight' => (float) $row['weight'],
                'contract_value' => isset($row['contract_value']) && $row['contract_value'] !== '' ? (float) $row['contract_value'] : null,
                'sort_order' => $i,
            ]);
            $stage->project_id = $project->id;
            $stage->save();
            $keep[] = $stage->id;
        }

        ProjectStage::query()->where('project_id', $project->id)->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  array<int, int>  $engineers
     * @param  array<int, int>  $supervisors
     */
    public function replaceTeam(Project $project, array $engineers, array $supervisors, array $coordinators = []): void
    {
        ProjectMember::query()->where('project_id', $project->id)->delete();

        foreach (array_unique($engineers) as $id) {
            ProjectMember::query()->create(['project_id' => $project->id, 'employee_id' => $id, 'role' => ProjectMember::ROLE_SITE_ENGINEER]);
        }

        foreach (array_diff(array_unique($supervisors), $engineers) as $id) {
            ProjectMember::query()->create(['project_id' => $project->id, 'employee_id' => $id, 'role' => ProjectMember::ROLE_SITE_SUPERVISOR]);
        }

        foreach (array_diff(array_unique($coordinators), $engineers, $supervisors) as $id) {
            ProjectMember::query()->create(['project_id' => $project->id, 'employee_id' => $id, 'role' => ProjectMember::ROLE_COORDINATOR]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function subcontractorFields(array $row): array
    {
        return collect($row)->only([
            'name', 'trade', 'contact_name', 'contact_phone', 'contract_value', 'billed_amount',
            'project_stage_id', 'start_date', 'end_date',
        ])->all();
    }
}
