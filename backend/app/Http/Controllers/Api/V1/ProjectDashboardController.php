<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\ProjectSubcontractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/project-dashboard[?status=&search=]
 *
 * Project Manager and above (`projects.dashboard.view`): for every project,
 *  1. progress — per system and total,
 *  2. value — per system and total,
 *  3. what the company has billed, amount and %,
 *  4. installation subcontractors — contract value, billed, %.
 * And the same totals across all the projects listed.
 */
class ProjectDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = Project::query()
            ->with(['projectManager', 'stages', 'subcontractors.stage'])
            ->orderBy('name');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        } else {
            $query->whereNotIn('status', [Project::STATUS_CANCELLED]);
        }

        if ($term = trim((string) $request->query('search', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('client', 'like', $like));
        }

        $projects = $query->limit(300)->get()->map(fn (Project $p) => $this->project($p));

        $value = $projects->sum('value_total');
        $billed = $projects->sum('billed_total');
        $subValue = $projects->sum('subcontract_value_total');
        $subBilled = $projects->sum('subcontract_billed_total');

        return ApiResponse::success('Project dashboard.', [
            'totals' => [
                'projects' => $projects->count(),
                'behind_schedule' => $projects->where('behind_schedule', true)->count(),
                'value_total' => round($value, 2),
                'billed_total' => round($billed, 2),
                'billed_percent' => $this->percent($billed, $value),
                // Value-weighted progress across the projects.
                'progress_percent' => $value > 0
                    ? round($projects->sum(fn ($p) => $p['value_total'] * $p['progress_percent']) / $value, 2)
                    : round((float) $projects->avg('progress_percent'), 2),
                'subcontract_value_total' => round($subValue, 2),
                'subcontract_billed_total' => round($subBilled, 2),
                'subcontract_billed_percent' => $this->percent($subBilled, $subValue),
            ],
            'items' => $projects->values(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function project(Project $p): array
    {
        $systems = $p->stages->map(fn (ProjectStage $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'weight' => (float) $s->weight,
            'progress_percent' => (float) $s->percent_complete,
            'value' => (float) ($s->contract_value ?? 0),
            'billed_amount' => (float) $s->billed_amount,
            'billed_percent' => $this->percent((float) $s->billed_amount, (float) ($s->contract_value ?? 0)),
        ])->values();

        $subs = $p->subcontractors
            ->where('status', ProjectSubcontractor::STATUS_ACTIVE)
            ->map(fn (ProjectSubcontractor $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'trade' => $c->trade,
                'system' => $c->stage?->name,
                'contract_value' => (float) ($c->contract_value ?? 0),
                'billed_amount' => (float) $c->billed_amount,
                'billed_percent' => $this->percent((float) $c->billed_amount, (float) ($c->contract_value ?? 0)),
            ])->values();

        $value = (float) $systems->sum('value');
        $billed = (float) $systems->sum('billed_amount');
        $subValue = (float) $subs->sum('contract_value');
        $subBilled = (float) $subs->sum('billed_amount');

        return [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'client' => $p->client,
            'status' => $p->status,
            'project_manager' => $p->projectManager?->full_name,
            'progress_percent' => (float) $p->progress_percent,
            'expected_percent' => $p->expectedPercent(),
            'behind_schedule' => $p->isBehindSchedule(),
            'value_total' => round($value, 2),
            'billed_total' => round($billed, 2),
            'billed_percent' => $this->percent($billed, $value),
            'systems' => $systems,
            'subcontract_value_total' => round($subValue, 2),
            'subcontract_billed_total' => round($subBilled, 2),
            'subcontract_billed_percent' => $this->percent($subBilled, $subValue),
            'subcontractors' => $subs,
        ];
    }

    private function percent(float $part, float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : null;
    }
}
