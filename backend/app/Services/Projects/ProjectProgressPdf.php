<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectPaymentMilestone;
use App\Models\ProjectProgressUpdate;
use App\Models\ProjectStage;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * One-page progress report for a project, for the client or management:
 * overall %, expected %, stages, payment milestones and recent changes.
 * Built on demand, never stored.
 */
class ProjectProgressPdf
{
    public function response(Project $project): Response
    {
        $bytes = Pdf::loadHTML($this->html($project), 'UTF-8')
            ->setPaper('a4', 'portrait')
            ->output();

        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($project->code ?: $project->id));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="progress-'.$name.'-'.now()->toDateString().'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function html(Project $project): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%';

        $project->loadMissing('projectManager');
        $expected = $project->expectedPercent();

        $stages = $project->stages()->get()->map(fn (ProjectStage $s) => '<tr><td>'.$e($s->name).'</td><td class="r">'.$pct($s->weight).'</td><td class="r">'.$pct($s->percent_complete).'</td>'
            .'<td><div class="bar"><div style="width:'.max(0, min(100, (float) $s->percent_complete)).'%"></div></div></td></tr>')->implode('');

        $milestones = $project->paymentMilestones()->get()->map(fn (ProjectPaymentMilestone $m) => '<tr><td>'.$e($m->name).'</td><td class="r">'.$pct($m->trigger_percent).'</td><td>'.$e(ucfirst($m->status)).'</td></tr>')->implode('');

        $history = ProjectProgressUpdate::query()->with(['stage', 'user'])->where('project_id', $project->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->get()
            ->map(fn (ProjectProgressUpdate $u) => '<tr><td>'.$e($u->created_at?->format('d M Y')).'</td><td>'.$e($u->stage?->name).'</td><td class="r">'.$pct($u->stage_percent).'</td><td class="r">'.$pct($u->project_percent).'</td><td>'.$e($u->user?->name).'</td></tr>')->implode('');

        $status = $project->isBehindSchedule() ? '<span class="late">Behind schedule</span>' : 'On track';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
            h1{font-size:18px;margin:0 0 4px} h2{font-size:13px;margin:18px 0 6px;border-bottom:1px solid #ccc;padding-bottom:3px}
            table{width:100%;border-collapse:collapse} td,th{padding:4px 6px;border-bottom:1px solid #eee;text-align:left}
            th{background:#f3f4f6} .r{text-align:right} .muted{color:#666}
            .big{font-size:28px;font-weight:bold} .late{color:#b91c1c;font-weight:bold}
            .bar{background:#e5e7eb;height:8px;width:120px} .bar div{background:#2563eb;height:8px}
        </style></head><body>
        <h1>'.$e($project->name).'</h1>
        <div class="muted">'.$e($project->code).' · Client: '.$e($project->client ?: '—').' · Main contractor: '.$e($project->main_contractor ?: '—').'</div>
        <div class="muted">Project Manager: '.$e($project->projectManager?->full_name ?: '—').' · '.$e($project->start_date?->format('d M Y') ?: '—').' to '.$e($project->end_date?->format('d M Y') ?: '—').'</div>
        <h2>Progress</h2>
        <table><tr><td><div class="big">'.$pct($project->progress_percent).'</div>complete</td>
        <td>Expected by today: <b>'.$pct($expected).'</b><br>Status: '.$status.'<br><span class="muted">Report date: '.$e(now()->format('d M Y')).'</span></td></tr></table>
        <h2>Stages</h2>
        <table><tr><th>Stage</th><th class="r">Weight</th><th class="r">Done</th><th></th></tr>'.($stages ?: '<tr><td colspan="4">No stages.</td></tr>').'</table>
        <h2>Payment milestones</h2>
        <table><tr><th>Milestone</th><th class="r">At</th><th>Status</th></tr>'.($milestones ?: '<tr><td colspan="3">None set.</td></tr>').'</table>
        <h2>Recent updates</h2>
        <table><tr><th>Date</th><th>Stage</th><th class="r">Stage</th><th class="r">Project</th><th>By</th></tr>'.($history ?: '<tr><td colspan="5">No updates yet.</td></tr>').'</table>
        </body></html>';
    }
}
