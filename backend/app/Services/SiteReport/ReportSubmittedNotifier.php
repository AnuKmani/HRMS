<?php

namespace App\Services\SiteReport;

use App\Models\Project;
use App\Services\Notifications\NotificationMessage;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Model;

/**
 * A Site Engineer or Site Supervisor submitted a report: it goes to the
 * Project Manager of that project — the one person who tracks them.
 */
class ReportSubmittedNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function notify(Model $report, string $kind, string $route, string $authorName): void
    {
        $project = $report->project_id !== null
            ? Project::query()->with('projectManager')->find($report->project_id)
            : null;
        $managerUserId = $project?->projectManager?->user_id;

        if ($managerUserId === null) {
            return;
        }

        $date = $report->report_date ?? $report->submitted_at;

        $this->notifications->send(new NotificationMessage(
            type: 'site_report.submitted',
            title: "New {$kind}",
            body: sprintf(
                '%s submitted a %s for %s%s.',
                $authorName,
                $kind,
                $project->name,
                $date !== null ? ' ('.$date->format('j M Y').')' : '',
            ),
            recipients: [(int) $managerUserId],
            data: ['route' => $route],
        ));
    }
}
