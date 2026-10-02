<?php

namespace App\Services\Notifications;

use App\Models\ApprovalRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who is waiting on a decision, expressed as user ids.
 *
 * A submitted request is only interesting to the people who can answer it,
 * and the chain that decides that is already materialised in
 * `approval_records` when the request goes out. So this reads the chain
 * instead of guessing: "notify everybody with `leaves.approve`" would
 * ping people who have nothing to do with this request, and would be a
 * second copy of the approver-resolution rules to keep in step with
 * `ApprovalWorkflowService::matches()` the first time a workflow changed.
 *
 * Only **open** steps count (`pending`, the one holding the request right
 * now, and `waiting`, the ones queued behind it) — an already-decided step
 * has no live audience, and notifying it would tell people about a request
 * they will never be asked about.
 */
class ApprovalAudience
{
    /**
     * User ids who could act on any open step of this subject.
     *
     * @return array<int, int>
     */
    public function openApprovers(string $subjectType, int $subjectId): array
    {
        $records = ApprovalRecord::query()
            ->forSubject($subjectType, $subjectId)
            ->whereIn('status', [ApprovalRecord::STATUS_PENDING, ApprovalRecord::STATUS_WAITING])
            ->get();

        $ids = [];

        foreach ($records as $record) {
            foreach ($this->resolve($record) as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * One open step, expanded to the users it can be answered by.
     *
     * Mirrors `ApprovalWorkflowService::matches()` — that method answers
     * "does this particular person fit?", this one answers "who fits?", and
     * there is no shared predicate that answers both without dragging the
     * workflow service into the notification layer.
     *
     * @return array<int, int>
     */
    private function resolve(ApprovalRecord $record): array
    {
        $ids = match ($record->approver_type) {
            'reporting_manager' => $record->approver_employee_id !== null
                ? $this->employeeUserIds([(int) $record->approver_employee_id])
                : [],
            'role' => $record->approver_role !== null
                ? $this->userIdsWithRole($record->approver_role)
                : [],
            'permission' => $record->approver_permission !== null
                ? $this->userIdsWithPermission($record->approver_permission)
                : [],
            default => [],
        };

        return $ids;
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, int>
     */
    private function employeeUserIds(array $employeeIds): array
    {
        return DB::table('employees')
            ->whereIn('id', $employeeIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function userIdsWithRole(string $role): array
    {
        return User::query()
            ->role($role)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function userIdsWithPermission(string $permission): array
    {
        return User::query()
            ->permission($permission)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
