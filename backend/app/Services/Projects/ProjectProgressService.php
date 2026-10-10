<?php

namespace App\Services\Projects;

use App\Models\DailySiteReport;
use App\Models\Project;
use App\Models\ProjectPaymentMilestone;
use App\Models\ProjectProgressUpdate;
use App\Models\ProjectStage;
use App\Models\User;
use App\Services\Notifications\NotificationMessage;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Project progress = the weighted average of its stages' completion.
 *
 * Stage completion comes from the daily site reports the engineers and
 * supervisors submit (the latest report wins, so a wrong figure can be
 * corrected the next day), or from the Project Manager correcting a stage
 * by hand. Every change is kept, which is the progress history.
 *
 * When progress crosses a payment milestone, Sales is told it can raise
 * the payment request.
 */
class ProjectProgressService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** A submitted daily report's stage figures become the project's. */
    public function applyReport(DailySiteReport $report, ?User $by = null): void
    {
        $rows = DB::table('daily_site_report_stage_progress')
            ->where('daily_site_report_id', $report->id)
            ->get();

        if ($rows->isEmpty() || $report->project_id === null) {
            return;
        }

        $project = Project::query()->findOrFail($report->project_id);

        DB::transaction(function () use ($rows, $project, $report, $by) {
            foreach ($rows as $row) {
                $stage = ProjectStage::query()
                    ->where('project_id', $project->id)
                    ->find($row->project_stage_id);

                if ($stage === null || (float) $stage->percent_complete === (float) $row->percent) {
                    continue;
                }

                $stage->forceFill(['percent_complete' => $row->percent])->save();
                $this->log($project, $stage, ProjectProgressUpdate::SOURCE_DAILY_REPORT, $by?->id, null, $report->id);
            }

            $this->recompute($project);
        });
    }

    /** The Project Manager corrects one stage by hand. */
    public function correct(Project $project, ProjectStage $stage, float $percent, User $by, ?string $note): void
    {
        DB::transaction(function () use ($project, $stage, $percent, $by, $note) {
            $stage->forceFill(['percent_complete' => $percent])->save();
            $this->log($project, $stage, ProjectProgressUpdate::SOURCE_MANUAL, $by->id, $note, null);
            $this->recompute($project);
        });
    }

    /**
     * Recalculate the project's % from its stages, keep the latest history
     * row's project figure in step, and announce milestones it crossed.
     */
    public function recompute(Project $project): float
    {
        $stages = ProjectStage::query()->where('project_id', $project->id)->get();
        $weight = (float) $stages->sum('weight');

        $percent = $weight > 0
            ? round($stages->sum(fn (ProjectStage $s) => (float) $s->weight * (float) $s->percent_complete) / $weight, 2)
            : 0.0;

        $project->forceFill([
            'progress_percent' => $percent,
            'progress_updated_at' => now(),
        ])->save();

        ProjectProgressUpdate::query()
            ->where('project_id', $project->id)
            ->latest('id')
            ->limit(1)
            ->update(['project_percent' => $percent]);

        $this->announceMilestones($project, $percent);

        return $percent;
    }

    private function log(Project $project, ProjectStage $stage, string $source, ?int $userId, ?string $note, ?int $reportId): void
    {
        ProjectProgressUpdate::query()->create([
            'project_id' => $project->id,
            'project_stage_id' => $stage->id,
            'stage_percent' => $stage->percent_complete,
            'project_percent' => $project->progress_percent ?? 0,
            'source' => $source,
            'daily_site_report_id' => $reportId,
            'user_id' => $userId,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function announceMilestones(Project $project, float $percent): void
    {
        $reached = ProjectPaymentMilestone::query()
            ->where('project_id', $project->id)
            ->where('status', ProjectPaymentMilestone::STATUS_PENDING)
            ->where('trigger_percent', '<=', $percent)
            ->get();

        if ($reached->isEmpty()) {
            return;
        }

        $sales = User::permission('projects.billing.manage')
            ->where('status', User::STATUS_ACTIVE)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($reached as $milestone) {
            $milestone->forceFill([
                'status' => ProjectPaymentMilestone::STATUS_REACHED,
                'reached_at' => now(),
            ])->save();

            $recipients = array_values(array_unique(array_filter([
                ...$sales,
                $project->created_by !== null ? (int) $project->created_by : null,
            ])));

            if ($recipients === []) {
                continue;
            }

            $this->notifications->send(new NotificationMessage(
                type: 'project.milestone_reached',
                title: 'Payment milestone reached',
                body: sprintf(
                    '%s reached %s%% — "%s" (at %s%%) can now be requested.',
                    $project->name,
                    rtrim(rtrim(number_format($percent, 2), '0'), '.'),
                    $milestone->name,
                    rtrim(rtrim(number_format((float) $milestone->trigger_percent, 2), '0'), '.'),
                ),
                recipients: $recipients,
                data: ['route' => '/project-progress/'.$project->id],
            ));
        }
    }
}
