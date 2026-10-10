<?php

namespace App\Services\Attendance;

use App\Models\SiteVisit;
use App\Models\SiteVisitTask;
use App\Services\Notifications\NotificationMessage;
use App\Services\Notifications\NotificationService;

/**
 * Completing a planned site visit, and telling the two people involved.
 */
class SiteVisitTaskService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function complete(SiteVisitTask $task, ?SiteVisit $visit = null, ?string $note = null): SiteVisitTask
    {
        if ($task->status !== SiteVisitTask::STATUS_PLANNED) {
            return $task;
        }

        $task->forceFill([
            'status' => SiteVisitTask::STATUS_COMPLETED,
            'completed_at' => now(),
            'site_visit_id' => $visit?->id ?? $task->site_visit_id,
            'completion_note' => $note ?? $task->completion_note,
        ])->save();

        $task->loadMissing(['employee', 'project', 'site']);

        if ($task->assigned_by !== null) {
            $this->notifications->send(new NotificationMessage(
                type: 'site_task.completed',
                title: 'Planned site visit completed',
                body: sprintf(
                    '%s completed the visit to %s on %s.',
                    $task->employee?->full_name ?? 'The engineer',
                    $this->where($task),
                    $task->visit_date?->format('j M Y') ?? '',
                ),
                recipients: [(int) $task->assigned_by],
                data: ['route' => '/site-visit-plans'],
            ));
        }

        return $task;
    }

    /**
     * A site visit just ended: any planned visit for the same engineer, the
     * same day and that place is done. The place matches on the site when
     * the plan named one, otherwise on the project.
     */
    public function completeFromVisit(SiteVisit $visit): void
    {
        $day = $visit->started_at?->toDateString();

        if ($day === null) {
            return;
        }

        SiteVisitTask::query()
            ->where('employee_id', $visit->employee_id)
            ->where('status', SiteVisitTask::STATUS_PLANNED)
            ->whereDate('visit_date', $day)
            ->where(function ($query) use ($visit) {
                $query->where('site_id', $visit->site_id)
                    ->orWhere(function ($inner) use ($visit) {
                        $inner->whereNull('site_id')->where('project_id', $visit->project_id);
                    });
            })
            ->get()
            ->each(fn (SiteVisitTask $task) => $this->complete($task, $visit));
    }

    public function notifyAssigned(SiteVisitTask $task): void
    {
        $task->loadMissing(['employee', 'project', 'site']);
        $userId = $task->employee?->user_id;

        if ($userId === null) {
            return;
        }

        $this->notifications->send(new NotificationMessage(
            type: 'site_task.assigned',
            title: 'Site visit planned for you',
            body: sprintf(
                'Visit %s on %s: %s',
                $this->where($task),
                $task->visit_date?->format('D j M Y') ?? '',
                $task->purpose,
            ),
            recipients: [(int) $userId],
            data: ['route' => '/site-visit-plans'],
        ));
    }

    private function where(SiteVisitTask $task): string
    {
        $project = $task->project?->name ?? 'the project';

        return $task->site?->name !== null ? $task->site->name.' ('.$project.')' : $project;
    }
}
