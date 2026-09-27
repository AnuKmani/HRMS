<?php

namespace App\Services\Attendance;

use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Site;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Every write to `attendances` happens in this class.
 *
 * That is a deliberate constraint rather than tidiness. Check-in and
 * check-out are the two moments where an employee's word is being turned
 * into a payroll input, so the whole sequence — who, where, when, how far,
 * which shift, which status — has to be one transaction with one order. A
 * controller that assembled the row itself would eventually grow a second
 * caller that got one of those steps wrong.
 *
 * Order inside `checkIn()`, and why:
 *
 *   1. replay a known `client_event_id` — an offline queue retrying a
 *      submission whose response was lost must get the same row back, not a
 *      second 409 and not a second attendance;
 *   2. refuse a second row for the same (employee, date) — the unique index
 *      backs this up, but the message has to be ours;
 *   3. refuse while a previous day's attendance is still open — checking in
 *      on top of an unclosed yesterday would leave a day nobody can ever
 *      finish;
 *   4. site access (403) before geofence (422): authorisation first, so a
 *      person who is not posted here is told that, not told they are 900 m
 *      away from a site they were never meant to be at;
 *   5. geofence and GPS quality;
 *   6. schedule, lateness, status — all derived, none supplied;
 *   7. selfie, then the insert. The file is written last so a rejection in
 *      steps 1-6 never leaves one behind, and it is removed again if the
 *      insert itself fails.
 *
 * Audit preparation: `AttendanceService` is the complete set of mutations.
 * A listener on Attendance::created / Attendance::updated would observe
 * every change the API can make, and no such listener exists until the audit
 * module lands (docs/SECURITY.md).
 */
final class AttendanceService
{
    public function __construct(
        private readonly ScheduleResolver $schedule,
        private readonly WorkingTimeCalculator $time,
        private readonly AttendanceStatusCalculator $statuses,
        private readonly GeofenceService $geofence,
        private readonly SiteAccessValidator $siteAccess,
        private readonly SelfieStore $selfies,
        private readonly SettingsService $settings,
    ) {}

    /* ------------------------------------------------------------- check-in */

    /**
     * @param  array<string, mixed>  $data  validated by StoreCheckInRequest
     */
    public function checkIn(User $user, array $data): Attendance
    {
        $employee = ClientInput::employeeFor($user);
        $site = Site::query()->findOrFail($data['site_id']);

        $selfiePath = null;

        try {
            return DB::transaction(function () use ($employee, $site, $data, &$selfiePath) {
                $eventId = ClientInput::eventId($data);

                // 1. Replay, not refuse.
                if ($eventId !== null) {
                    $replay = Attendance::query()
                        ->where('client_event_id', $eventId)
                        ->lockForUpdate()
                        ->first();

                    if ($replay !== null) {
                        return $replay;
                    }
                }

                $now = now();
                $date = $now->toDateString();

                // 2. One attendance per employee per day. lockForUpdate closes
                //    the gap between the SELECT and the INSERT so two phones
                //    tapping at once serialise instead of both winning.
                $already = Attendance::query()
                    ->where('employee_id', $employee->id)
                    ->where('attendance_date', $date)
                    ->lockForUpdate()
                    ->first();

                if ($already !== null) {
                    abort(409, 'You have already checked in today.');
                }

                // 3. Nothing may start while something else is unfinished.
                $open = $this->openAttendanceQuery($employee)->lockForUpdate()->first();

                if ($open !== null) {
                    abort(
                        409,
                        sprintf(
                            'You checked in on %s and have not checked out yet. Check out before starting a new day.',
                            $open->attendance_date->toDateString(),
                        ),
                    );
                }

                // 4. Authorised to be here at all?
                $access = $this->siteAccess->authorize($employee, $site, $now);

                if (! $access->allowed) {
                    abort(403, $access->message);
                }

                // 5. Actually here?
                $point = ClientInput::point($data);
                $result = $this->geofence->evaluate(
                    $site,
                    $point['latitude'],
                    $point['longitude'],
                    $point['accuracy'],
                );

                if (! $result->allowed) {
                    throw ValidationException::withMessages(['location' => $result->message]);
                }

                // 6. What kind of day is this? Decided here, never supplied.
                $plan = $this->schedule->planFor($site, $now);
                $metrics = $this->time->atCheckIn($plan, $now);

                // 7. Evidence, then the row.
                $selfiePath = $this->selfies->store(
                    ClientInput::selfieFile($data),
                    $employee,
                );

                $attendance = new Attendance;
                $attendance->employee_id = $employee->id;
                $attendance->project_id = $site->project_id;
                $attendance->site_id = $site->id;
                $attendance->attendance_date = $date;
                $attendance->check_in_at = $now;
                $attendance->check_in_latitude = $point['latitude'];
                $attendance->check_in_longitude = $point['longitude'];
                $attendance->check_in_accuracy = $point['accuracy'];
                $attendance->check_in_distance = $result->distanceMetresRounded();
                $attendance->check_in_selfie_path = $selfiePath;
                $attendance->shift_id = $plan->shiftId;
                $attendance->scheduled_start_at = $plan->scheduledStart;
                $attendance->scheduled_end_at = $plan->scheduledEnd;
                $attendance->late_minutes = $metrics->lateMinutes;
                $attendance->status = $this->statuses->atCheckIn($metrics);
                $attendance->source = ClientInput::source($data);
                $attendance->device_reference = ClientInput::deviceReference($data);
                $attendance->client_event_id = $eventId;
                $attendance->save();

                return $attendance;
            });
        } catch (Throwable $exception) {
            // The row did not survive, so its evidence must not either: an
            // orphaned selfie in private storage is still somebody's face
            // sitting in a directory nothing will ever read.
            if ($selfiePath !== null) {
                $this->selfies->delete($selfiePath);
            }

            throw $exception;
        }
    }

    /* ------------------------------------------------------------ check-out */

    /**
     * @param  array<string, mixed>  $data  validated by StoreCheckOutRequest
     */
    public function checkOut(User $user, array $data): Attendance
    {
        $employee = ClientInput::employeeFor($user);
        $site = Site::query()->findOrFail($data['site_id']);
        $eventId = ClientInput::eventId($data);

        $selfiePath = null;

        try {
            return DB::transaction(function () use ($employee, $site, $data, $eventId, &$selfiePath) {
                if ($eventId !== null) {
                    $replay = Attendance::query()
                        ->where('check_out_client_event_id', $eventId)
                        ->lockForUpdate()
                        ->first();

                    if ($replay !== null && $replay->check_out_at !== null) {
                        return $replay;
                    }
                }

                $today = Attendance::query()
                    ->where('employee_id', $employee->id)
                    ->where('attendance_date', now()->toDateString())
                    ->lockForUpdate()
                    ->first();

                if ($today !== null && $today->check_out_at !== null) {
                    abort(409, 'You have already checked out today.');
                }

                // The row being closed may be yesterday's — that is exactly
                // the missing-checkout case, and it is closed here rather
                // than silently abandoned.
                $open = $this->openAttendanceQuery($employee)->lockForUpdate()->first();

                if ($open === null) {
                    throw ValidationException::withMessages([
                        'check_out' => 'You have not checked in yet, so there is nothing to check out of.',
                    ]);
                }

                if ((int) $open->site_id !== (int) $site->id) {
                    throw ValidationException::withMessages([
                        'site_id' => sprintf(
                            'You checked in at %s. Check out from the same site.',
                            $open->site?->name ?? 'that site',
                        ),
                    ]);
                }

                $point = ClientInput::point($data);

                if ($this->shouldValidateCheckoutGeofence()) {
                    $result = $this->geofence->evaluate(
                        $site,
                        $point['latitude'],
                        $point['longitude'],
                        $point['accuracy'],
                    );

                    if (! $result->allowed) {
                        throw ValidationException::withMessages(['location' => $result->message]);
                    }

                    $distance = $result->distanceMetresRounded();
                } else {
                    // Deployment has turned checkout geofencing off; the
                    // coordinates are still recorded, they just decide
                    // nothing. Nothing is invented in their place.
                    $distance = null;
                }

                $plan = $this->planFor($open);
                $metrics = $this->time->atCheckOut($plan, $open->check_in_at, now());

                // No check-out selfie in Phase 5: there is deliberately no
                // `check_out_selfie_path` column, and storing a file with
                // nowhere to record it would leave personal data in storage
                // that nothing could ever find — or delete.

                $open->check_out_at = now();
                $open->check_out_latitude = $point['latitude'];
                $open->check_out_longitude = $point['longitude'];
                $open->check_out_accuracy = $point['accuracy'];
                $open->check_out_distance = $distance;
                $open->working_minutes = $metrics->workingMinutes;
                $open->break_minutes = $metrics->breakMinutes;
                $open->overtime_minutes = $metrics->overtimeMinutes;
                $open->late_minutes = $metrics->lateMinutes;
                $open->early_departure_minutes = $metrics->earlyDepartureMinutes;
                $open->status = $this->statuses->atCheckOut(
                    $plan,
                    $this->time->atCheckIn($plan, $open->check_in_at),
                    $metrics,
                );
                $open->source = $open->source === Attendance::SOURCE_OFFLINE
                    ? Attendance::SOURCE_OFFLINE
                    : ClientInput::source($data);
                $open->device_reference = ClientInput::deviceReference($data) ?? $open->device_reference;
                $open->check_out_client_event_id = $eventId;
                $open->save();

                return $open;
            });
        } catch (Throwable $exception) {
            if ($selfiePath !== null) {
                $this->selfies->delete($selfiePath);
            }

            throw $exception;
        }
    }

    /* ---------------------------------------------------------------- today */

    /**
     * Everything the check-in screen needs, and nothing it does not.
     *
     * @return array<string, mixed>
     */
    public function today(User $user): array
    {
        $employee = ClientInput::employeeFor($user);

        // Reading today is the natural moment to notice a day that ended
        // without a checkout, so the flag is written here — once, by the one
        // method that owns the rule.
        $this->flagMissingCheckouts($employee);

        $today = Attendance::query()
            ->with(['site.project', 'project', 'shift'])
            ->where('employee_id', $employee->id)
            ->where('attendance_date', now()->toDateString())
            ->first();

        $open = $this->openAttendanceQuery($employee)
            ->with(['site.project', 'shift'])
            ->first();

        // The row awaiting a checkout: today's if it is still open, otherwise
        // whatever the open query found — which is null on an ordinary
        // already-closed day, and yesterday's straggler when today has not
        // begun. `openAttendanceQuery` orders by arrival descending, so on a
        // day in progress it *is* today's row.
        $pending = $open;

        $site = $today?->site ?? $pending?->site ?? $this->primarySiteFor($employee);

        $plan = $site !== null
            ? $this->schedule->planFor($site, now())
            : ShiftPlan::none();

        $assignments = $this->assignedSites($employee);

        $checkedIn = $today?->check_in_at !== null;
        $checkedOut = $today?->check_out_at !== null;

        return [
            'date' => now()->toDateString(),
            'server_time' => now()->toIso8601String(),
            'employee_id' => $employee->id,

            'attendance' => $today !== null ? new AttendanceResource($today) : null,
            'open_attendance' => $pending !== null ? new AttendanceResource($pending) : null,

            'checked_in' => $checkedIn,
            'checked_out' => $checkedOut,

            'site' => $site !== null ? $this->siteSummary($site) : null,
            'sites' => array_map(fn (Site $row) => $this->siteSummary($row), $assignments),

            'shift' => $plan->hasSchedule ? [
                'shift_id' => $plan->shiftId,
                'starts_at' => $plan->scheduledStart?->toDateTimeString(),
                'ends_at' => $plan->scheduledEnd?->toDateTimeString(),
                'grace_minutes' => $plan->graceMinutes,
                'break_minutes' => $plan->breakMinutes,
                'minimum_working_minutes' => $plan->minimumWorkingMinutes,
                'overtime_threshold_minutes' => $plan->overtimeThresholdMinutes,
                'crosses_midnight' => $plan->crossesMidnight,
            ] : null,

            'working_minutes' => (int) ($today?->working_minutes ?? 0),
            'late_minutes' => (int) ($today?->late_minutes ?? 0),

            // The accuracy ceiling the server will apply, sent down so the
            // phone's advisory geofence uses the same number rather than a
            // copy of one that would drift the moment this config changed.
            'max_gps_accuracy_metres' => (float) config('hrms.attendance.max_gps_accuracy_metres', 100),

            'can_check_in' => ! $checkedIn && $pending === null && $site !== null,
            'can_check_out' => $pending !== null,
            'can_start_site_visit' => $assignments !== [],
        ];
    }

    /* -------------------------------------------------------------- history */

    /**
     * Flag every one of this employee's unclosed days whose scheduled end has
     * already passed.
     *
     * One UPDATE, keyed on the employee, and idempotent — it may run on every
     * `today` read for a month without ever changing a row twice.
     */
    public function flagMissingCheckouts(Employee $employee): int
    {
        $today = now()->toDateString();

        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereNull('check_out_at')
            ->whereNotNull('check_in_at')
            ->where('status', '!=', Attendance::STATUS_MANUALLY_ADJUSTED)
            ->where(function ($query) use ($today) {
                $query->where('scheduled_end_at', '<', now())
                    ->orWhere(function ($inner) use ($today) {
                        $inner->whereNull('scheduled_end_at')
                            ->where('attendance_date', '<', $today);
                    });
            })
            ->update(['status' => $this->statuses->missingCheckout()]);
    }

    public function openAttendanceQuery(Employee $employee)
    {
        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->whereNull('check_out_at')
            ->whereNotNull('check_in_at')
            ->orderByDesc('check_in_at');
    }

    /* ------------------------------------------------------------- internals */

    /**
     * The plan actually in force for this row: times as frozen at check-in,
     * allowances as the schedule currently states them.
     */
    private function planFor(Attendance $attendance): ShiftPlan
    {
        $resolved = $this->schedule->planFor($attendance->site, $attendance->attendance_date);

        if ($attendance->scheduled_start_at === null && $attendance->scheduled_end_at === null) {
            return $resolved;
        }

        return new ShiftPlan(
            shiftId: $attendance->shift_id ?? $resolved->shiftId,
            scheduledStart: $attendance->scheduled_start_at?->copy(),
            scheduledEnd: $attendance->scheduled_end_at?->copy(),
            graceMinutes: $resolved->graceMinutes,
            breakMinutes: $resolved->breakMinutes,
            minimumWorkingMinutes: $resolved->minimumWorkingMinutes,
            overtimeThresholdMinutes: $resolved->overtimeThresholdMinutes,
            crossesMidnight: $resolved->crossesMidnight,
            hasSchedule: true,
        );
    }

    private function shouldValidateCheckoutGeofence(): bool
    {
        return (bool) config('hrms.attendance.validate_checkout_geofence', true);
    }

    /**
     * The sites this employee may check in at today — active assignments
     * first, then the profile's own primary site when no assignment rows
     * exist for it (the same rule SiteAccessValidator applies one row at a
     * time).
     *
     * @return array<int, Site>
     */
    private function assignedSites(Employee $employee): array
    {
        $today = now()->toDateString();

        $assigned = Site::query()
            ->where('status', Site::STATUS_ACTIVE)
            ->whereHas('assignments', function ($query) use ($employee, $today) {
                $query->where('employee_id', $employee->id)
                    ->where('status', EmployeeSiteAssignment::STATUS_ACTIVE)
                    ->where('start_date', '<=', $today)
                    ->where(function ($inner) use ($today) {
                        $inner->whereNull('end_date')->orWhere('end_date', '>=', $today);
                    });
            })
            ->with('project')
            ->orderBy('name')
            ->get();

        if ($employee->primary_site_id !== null) {
            $hasRow = $assigned->contains(fn (Site $row) => (int) $row->id === (int) $employee->primary_site_id);

            if (! $hasRow) {
                $primary = Site::query()
                    ->with('project')
                    ->find($employee->primary_site_id);

                if ($primary !== null && $primary->status === Site::STATUS_ACTIVE) {
                    $assigned = $assigned->push($primary)->sortBy('name')->values();
                }
            }
        }

        return $assigned->all();
    }

    private function primarySiteFor(Employee $employee): ?Site
    {
        if ($employee->primary_site_id === null) {
            return null;
        }

        return Site::query()->with('project')->find($employee->primary_site_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function siteSummary(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'code' => $site->code,
            'project_id' => $site->project_id,
            'project_name' => $site->project?->name,
            'latitude' => $site->latitude,
            'longitude' => $site->longitude,
            'geofence_radius' => $site->geofence_radius
                ?? $this->settings->int('attendance.default_geofence_radius_m', 100),
        ];
    }
}
