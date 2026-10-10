<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\Site;
use App\Models\SiteVisit;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bounded site visits: started, ended, never tracked.
 *
 * The rules mirror attendance deliberately — same geofence service, same
 * site authorisation, same idempotency key, same 409-for-already-open — so
 * there is one way this codebase decides whether somebody is at an address,
 * and a second implementation cannot quietly loosen it.
 *
 * What this class refuses to have: anything that records a position between
 * `start` and `end`. A visit has two points; the interval is a duration, not
 * a path.
 */
final class SiteVisitService
{
    public function __construct(
        private readonly GeofenceService $geofence,
        private readonly SiteAccessValidator $siteAccess,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by StartSiteVisitRequest
     */
    public function start(User $user, array $data): SiteVisit
    {
        $employee = ClientInput::employeeFor($user);
        $site = Site::query()->findOrFail($data['site_id']);
        $eventId = ClientInput::eventId($data);

        return DB::transaction(function () use ($employee, $site, $data, $eventId) {
            if ($eventId !== null) {
                $replay = SiteVisit::query()
                    ->where('client_event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($replay !== null) {
                    return $replay;
                }
            }

            $open = SiteVisit::query()
                ->open()
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                abort(409, 'You already have a site visit in progress. End it before starting another.');
            }

            // A field role may visit any active site on the company list —
            // no assignment needed. Being there is still checked below.
            if ($site->status !== Site::STATUS_ACTIVE) {
                abort(403, 'This site is not active.');
            }

            if ($employee->employment_status !== Employee::STATUS_ACTIVE) {
                abort(403, 'Your employment is not active. Contact HR.');
            }

            $point = ClientInput::point($data);
            $distance = $this->distanceFrom($site, $point);

            $visit = new SiteVisit;
            $visit->employee_id = $employee->id;
            $visit->project_id = $site->project_id;
            $visit->site_id = $site->id;
            $visit->started_at = now();
            $visit->start_latitude = $point['latitude'];
            $visit->start_longitude = $point['longitude'];
            $visit->start_accuracy = $point['accuracy'];
            $visit->start_distance = $distance;
            $visit->purpose = (string) $data['purpose'];
            $visit->remarks = $data['remarks'] ?? null;
            $visit->status = SiteVisit::STATUS_OPEN;
            $visit->client_event_id = $eventId;
            $visit->save();

            return $visit;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated by EndSiteVisitRequest
     */
    public function end(User $user, SiteVisit $visit, array $data): SiteVisit
    {
        $employee = ClientInput::employeeFor($user);

        // A visitor may only close their own visit; SiteVisitPolicy::end()
        // answers the same question for the route, and this is the belt.
        if ((int) $visit->employee_id !== (int) $employee->id) {
            abort(403, 'You can only end a site visit you started yourself.');
        }

        $eventId = ClientInput::eventId($data);

        return DB::transaction(function () use ($visit, $data, $eventId) {
            if ($eventId !== null) {
                $replay = SiteVisit::query()
                    ->where('end_client_event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($replay !== null && $replay->ended_at !== null) {
                    return $replay;
                }
            }

            $locked = SiteVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($locked->ended_at !== null) {
                abort(409, 'That site visit has already been ended.');
            }

            $point = ClientInput::point($data);
            $distance = $this->distanceFrom($locked->site, $point);

            $locked->ended_at = now();
            $locked->end_latitude = $point['latitude'];
            $locked->end_longitude = $point['longitude'];
            $locked->end_accuracy = $point['accuracy'];
            $locked->end_distance = $distance;
            $locked->remarks = $data['remarks'] ?? $locked->remarks;
            $locked->status = SiteVisit::STATUS_COMPLETED;
            $locked->end_client_event_id = $eventId;
            $locked->save();

            return $locked;
        });
    }

    /**
     * How far the phone was from the site, in metres — recorded, not
     * judged. A site visit is a record of where somebody went and when; a
     * weak GPS fix or a site nobody has pinned on the map must not stop it
     * being written. The distance (null when the site has no location) is
     * what a manager reads to see whether they were really there.
     *
     * A deployment that wants visits refused outside the site radius sets
     * `attendance.site_visit_requires_geofence`.
     *
     * @param  array{latitude: float, longitude: float, accuracy: ?float}  $point
     */
    private function distanceFrom(?Site $site, array $point): ?int
    {
        if ($site !== null && $this->settings->bool('attendance.site_visit_requires_geofence', false)) {
            $result = $this->geofence->evaluate($site, $point['latitude'], $point['longitude'], $point['accuracy']);

            if (! $result->allowed) {
                throw ValidationException::withMessages(['location' => $result->message]);
            }

            return $result->distanceMetresRounded();
        }

        if ($site === null || $site->latitude === null || $site->longitude === null) {
            return null;
        }

        return (int) round(Geo::distanceMetres(
            (float) $site->latitude,
            (float) $site->longitude,
            $point['latitude'],
            $point['longitude'],
        ));
    }

    /**
     * Today's visits for one employee — open one first, then finished.
     *
     * @return array<int, SiteVisit>
     */
    public function today(User $user): array
    {
        $employee = ClientInput::employeeFor($user);

        return SiteVisit::query()
            ->with(['site.project', 'project'])
            ->where('employee_id', $employee->id)
            ->whereDate('started_at', now()->toDateString())
            ->orderBy('started_at')
            ->get()
            ->all();
    }
}
