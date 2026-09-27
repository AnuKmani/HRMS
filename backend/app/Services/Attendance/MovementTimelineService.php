<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\SiteVisit;
use App\Models\User;

/**
 * What happened today, in order.
 *
 * Four event kinds, all of them *deliberate acts* — a check-in, a visit
 * start, a visit end, a check-out. There is deliberately no "location
 * updated" kind, because nothing in this system records a position nobody
 * asked it to (see docs/SECURITY.md, "no continuous tracking").
 *
 * Built by merging two already-filtered sets and sorting them, rather than
 * by a UNION in SQL: the sets are each at most a handful of rows for one
 * employee on one day, and the event shapes differ enough that two PHP
 * arrays with one `usort` is both clearer and easier to test than a query
 * whose projection has to satisfy both tables at once.
 */
final class MovementTimelineService
{
    /**
     * @return array{date: string, events: array<int, array<string, mixed>>}
     */
    public function today(User $user): array
    {
        $employee = ClientInput::employeeFor($user);
        $today = now()->toDateString();

        $events = [];

        $attendance = Attendance::query()
            ->with(['site.project', 'project'])
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $today)
            ->get();

        foreach ($attendance as $row) {
            if ($row->check_in_at !== null) {
                $events[] = $this->event(
                    'check_in',
                    $row->check_in_at,
                    $row->id,
                    $row,
                    $row->status,
                    sprintf('Checked in at %s', $row->site?->name ?? 'site'),
                );
            }

            if ($row->check_out_at !== null) {
                $events[] = $this->event(
                    'check_out',
                    $row->check_out_at,
                    $row->id,
                    $row,
                    $row->status,
                    sprintf('Checked out of %s', $row->site?->name ?? 'site'),
                    ['working_minutes' => (int) $row->working_minutes],
                );
            }
        }

        $visits = SiteVisit::query()
            ->with(['site.project', 'project'])
            ->where('employee_id', $employee->id)
            ->whereDate('started_at', $today)
            ->get();

        foreach ($visits as $visit) {
            $events[] = $this->event(
                'site_visit_start',
                $visit->started_at,
                $visit->id,
                $visit,
                $visit->status,
                sprintf('Started a site visit at %s', $visit->site?->name ?? 'site'),
                ['purpose' => $visit->purpose],
            );

            if ($visit->ended_at !== null) {
                $events[] = $this->event(
                    'site_visit_end',
                    $visit->ended_at,
                    $visit->id,
                    $visit,
                    $visit->status,
                    sprintf('Ended the site visit at %s', $visit->site?->name ?? 'site'),
                    ['duration_minutes' => $visit->durationMinutes()],
                );
            }
        }

        usort($events, fn (array $a, array $b) => strcmp($a['at'], $b['at']));

        return [
            'date' => $today,
            'events' => $events,
        ];
    }

    /**
     * @param  Attendance|SiteVisit  $record
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function event(
        string $type,
        $at,
        int $recordId,
        $record,
        string $status,
        string $label,
        array $extra = [],
    ): array {
        return array_merge([
            'type' => $type,
            'at' => $at?->toIso8601String(),
            'record_id' => $recordId,
            'site_id' => $record->site_id,
            'site_name' => $record->site?->name,
            'project_id' => $record->project_id,
            'project_name' => $record->site?->project?->name ?? $record->project?->name,
            'status' => $status,
            'label' => $label,
        ], $extra);
    }
}
