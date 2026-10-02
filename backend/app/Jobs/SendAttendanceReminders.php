<?php

namespace App\Jobs;

use App\Events\AttendanceReminder;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\Attendance\ScheduleResolver;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tell people they have not checked in for a shift that is starting.
 *
 * **Off by default.** `hrms.notifications.attendance_reminder_enabled` is
 * false out of the box, because a reminder nobody asked for is the fastest
 * way to get a notification channel muted — and a muted channel then
 * swallows the messages that matter. A deployment turns it on with one
 * environment variable and nothing else changes.
 *
 * Schedule: every fifteen minutes (see `routes/console.php`). Not because
 * the question is asked every fifteen minutes, but because the *answer
 * window* is short — see below — and one daily run would land either side
 * of it for most of a workforce's different shift times.
 *
 * Who is reminded, and why each condition is there:
 *
 *  - **active, with a login.** A resigned account and a person who never
 *    had a password are not people who can check in.
 *  - **no attendance row today.** Already in? Nothing to say.
 *  - **a site to be late for.** `ScheduleResolver::planFor()` needs the
 *    site to know what "on time" is; falling back to the organisation-wide
 *    default would tell a night crew they are late for a day shift.
 *  - **inside the reminder window.** From `scheduledStart - offset` (the
 *    seeded `notification.reminder_offset_minutes`, 15 by default) up to
 *    `scheduledStart + grace`. Before the window it is a guess; after it
 *    the person is simply late, and the attendance screen says so with the
 *    real numbers rather than a push saying "you are late" forever.
 *  - **not already told today.** Enforced by the message's dedupe key, not
 *    by a column here — see
 *    `EnforceSickCertificateDeadlines::remindUpcoming()` for why a clock
 *    should not own a marker.
 *
 * Idempotent for the same three reasons the other scheduled jobs are: the
 * query is the guard, the dedupe key is the second, and `ShouldBeUnique`
 * stops an overlapping run from even being dispatched.
 */
class SendAttendanceReminders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Ten minutes: longer than one tick, short enough that a failed run
     * still catches the same reminder window on its retry.
     */
    public int $uniqueFor = 600;

    /**
     * @return int how many reminders were raised
     */
    public function handle(ScheduleResolver $schedules, SettingsService $settings): int
    {
        if (! (bool) config('hrms.notifications.attendance_reminder_enabled', false)) {
            return 0;
        }

        $now = now();
        $today = $now->toDateString();
        $offset = max(0, $settings->int('notification.reminder_offset_minutes', 15));

        $eligible = Employee::query()
            ->where('employment_status', Employee::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->pluck('id');

        if ($eligible->isEmpty()) {
            return 0;
        }

        // One query for "who is already in" rather than a whereDoesntHave
        // per candidate: this runs every fifteen minutes, and the rows it
        // reads are the ones the busiest table of the day is writing.
        $present = Attendance::query()
            ->whereDate('attendance_date', $today)
            ->whereIn('employee_id', $eligible)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $candidates = Employee::query()
            ->whereIn('id', $eligible)
            ->whereNotIn('id', $present)
            ->with(['user', 'currentSiteAssignment.site', 'primarySite'])
            ->get();

        $raised = 0;

        foreach ($candidates as $employee) {
            if ($employee->user === null || ! $employee->user->isActive()) {
                continue;
            }

            $site = $employee->currentSiteAssignment?->site ?? $employee->primarySite;

            if ($site === null) {
                continue;
            }

            $plan = $schedules->planFor($site, $now->copy()->startOfDay());

            if (! $plan->hasSchedule || $plan->scheduledStart === null) {
                continue;
            }

            $windowOpens = $plan->scheduledStart->copy()->subMinutes($offset);
            $windowCloses = $plan->scheduledStart->copy()->addMinutes($plan->graceMinutes);

            if ($now->lt($windowOpens) || $now->gt($windowCloses)) {
                continue;
            }

            event(new AttendanceReminder($employee));

            $raised++;
        }

        return $raised;
    }
}
