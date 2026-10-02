<?php

namespace App\Services;

use App\Events\SiteAssigned;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * History, written deliberately.
 *
 * Every method here either ADDS a row or closes one. There is no method that
 * moves a posting, because "moving" is two events — the old placement ended
 * and a new one begun — and collapsing them into a single UPDATE is how a
 * workforce history turns into the current state of the world with last
 * month's truth deleted.
 *
 * Audit preparation: these two methods are the complete set of ways an
 * assignment changes. A listener on `EmployeeSiteAssignment::created` /
 * `saved` would capture every mutation the API can perform — nothing here
 * needs to change to make that possible, and no such listener is written
 * until the audit module lands.
 */
class EmployeeSiteAssignmentService
{
    /**
     * Place someone on a site, closing any primary posting they are still
     * holding.
     *
     * Only `primary` rows close their predecessor — `temporary` and
     * `additional` are by nature additive, and closing a permanent posting
     * because somebody borrowed them for a fortnight would be wrong.
     *
     * @param  array<string, mixed>  $data  already validated and authorised
     */
    public function create(array $data, User $creator): EmployeeSiteAssignment
    {
        return DB::transaction(function () use ($data, $creator) {
            $start = Carbon::parse($data['start_date']);

            if (($data['assignment_type'] ?? null) === EmployeeSiteAssignment::TYPE_PRIMARY
                && ($data['status'] ?? EmployeeSiteAssignment::STATUS_ACTIVE) === EmployeeSiteAssignment::STATUS_ACTIVE) {
                $this->closeExistingPrimary(
                    (int) $data['employee_id'],
                    $start,
                );
            }

            $assignment = new EmployeeSiteAssignment;
            $assignment->fill($data);
            // The table's `status` column defaults to 'active', but a default
            // only exists once the row is written. Without this the freshly
            // built model would report null for a posting the database just
            // called active — which is exactly the sort of disagreement
            // between memory and storage that a response must not carry.
            $assignment->status = $data['status'] ?? EmployeeSiteAssignment::STATUS_ACTIVE;
            $assignment->assignment_type = $data['assignment_type'] ?? EmployeeSiteAssignment::TYPE_PRIMARY;
            $assignment->created_by = $creator->id;
            $assignment->save();

            // Only an *active* posting is news. Closing one, or opening a
            // backdated row that was already in the past, would tell
            // somebody they had been posted to a site two weeks ago.
            if ($assignment->status === EmployeeSiteAssignment::STATUS_ACTIVE) {
                event(new SiteAssigned($assignment));
            }

            return $assignment;
        });
    }

    /**
     * Close a posting. The row keeps every fact about where somebody was;
     * only its conclusion changes.
     *
     * @param  array<string, mixed>  $data  already validated and authorised
     */
    public function close(EmployeeSiteAssignment $assignment, array $data): EmployeeSiteAssignment
    {
        return DB::transaction(function () use ($assignment, $data) {
            $assignment->status = $data['status'];

            if (array_key_exists('end_date', $data) && $data['end_date'] !== null) {
                $assignment->end_date = $data['end_date'];
            } elseif ($assignment->end_date === null) {
                // Closing without naming a date means "today" — the same
                // default `EmployeeSiteAssignment::end()` uses, so the two
                // paths cannot disagree.
                $assignment->end_date = now()->toDateString();
            }

            $assignment->save();

            return $assignment;
        });
    }

    /**
     * End the active primary posting so the new one starts from a clean
     * hand-over rather than two people (or one person twice) standing on the
     * same site.
     *
     * The closing date is the day before the new posting starts, which keeps
     * the two rows contiguous rather than overlapping. When that date would
     * fall before the old row's own start — the old posting was opening at
     * the very moment the new one begins — the old row is marked `cancelled`
     * instead, because an assignment that ran for less than a day never
     * described anything.
     */
    private function closeExistingPrimary(int $employeeId, Carbon $newStart): void
    {
        $open = EmployeeSiteAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('assignment_type', EmployeeSiteAssignment::TYPE_PRIMARY)
            ->where('status', EmployeeSiteAssignment::STATUS_ACTIVE)
            ->get();

        foreach ($open as $existing) {
            $boundary = $newStart->copy()->subDay();

            if ($boundary->lt($existing->start_date)) {
                $existing->status = EmployeeSiteAssignment::STATUS_CANCELLED;
            } else {
                $existing->status = EmployeeSiteAssignment::STATUS_ENDED;
                $existing->end_date = $boundary->toDateString();
            }

            $existing->save();
        }
    }

    /**
     * Whether this employee already holds an active primary posting — exposed
     * for the controller's response message, not for authorisation.
     */
    public function hasActivePrimary(Employee $employee): bool
    {
        return EmployeeSiteAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('assignment_type', EmployeeSiteAssignment::TYPE_PRIMARY)
            ->where('status', EmployeeSiteAssignment::STATUS_ACTIVE)
            ->exists();
    }
}
