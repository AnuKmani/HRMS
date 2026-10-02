<?php

namespace App\Http\Resources;

use App\Models\EmployeeTraining;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One enrolment in one training program, and the certificate it produced.
 *
 * Four things this payload is careful about, and three of them are the ones
 * an employment document's payload is careful about — deliberately, because
 * the questions are the same:
 *
 *  - **`certificate_path` never appears.** It is exactly the detail that
 *    should not leave the server: it says where private files live and
 *    hands a reader something to guess at. `has_certificate` says whether
 *    there is one, `certificate_file_url` points at the single route that
 *    will serve it behind EmployeeTrainingPolicy::viewCertificate(), and
 *    `certificate_original_name` is kept because "working-at-heights.jpg"
 *    is faster to recognise than a uuid — and is never used to open,
 *    serve or list anything.
 *
 *  - **`status` and `certificate_expiry_state` are two different facts.**
 *    `status` is the stored decision — `completed`, `expired`, `failed`.
 *    The state is computed from the date *right now* by
 *    EmployeeTraining::certificateExpiryState() and is authoritative on the
 *    server: Flutter renders it and never derives it. A `completed` row
 *    whose certificate expired yesterday reports `expired` whether or not
 *    the scheduler has caught up, so a lagging cron cannot make this API
 *    tell a lie.
 *
 *  - **`days_until_expiry` is signed.** Negative means already past; the
 *    sign carries the direction so no caller pairs the number with its own
 *    date comparison.
 *
 *  - **`file_url` is an API route, not a public one.** It needs the same
 *    bearer token as everything else, there is no signed link and nothing
 *    for a browser to cache.
 *
 * `is_editable` is a fact about this record's state rather than about the
 * reader — cancelled and failed are decided outcomes — for the reason
 * SiteActivityReportResource ships state and pointedly no `can_edit`.
 *
 * `trainer` is the *effective* trainer: the enrolment's own, or the
 * program's default when it named none. Resolved here rather than copied
 * onto the row so correcting a program's provider updates every cohort that
 * did not name one of its own, and `program_provider` ships beside it so a
 * screen can still show the distinction when the two differ.
 */
class EmployeeTrainingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = $this->certificateExpiryState();
        $days = $this->daysUntilCertificateExpiry();

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($this->employee)),
            'training_program_id' => $this->training_program_id,
            'training_program' => $this->whenLoaded(
                'trainingProgram',
                fn () => new TrainingProgramResource($this->trainingProgram),
            ),

            'enrollment_date' => $this->enrollment_date?->toDateString(),
            'training_date' => $this->training_date?->toDateString(),
            'completion_date' => $this->completion_date?->toDateString(),

            'trainer' => $this->effectiveTrainer(),
            'program_provider' => $this->whenLoaded(
                'trainingProgram',
                fn () => $this->trainingProgram?->provider,
            ),

            'status' => $this->status,
            'result' => $this->result,
            'remarks' => $this->remarks,

            'certificate_number' => $this->certificate_number,
            'certificate_issue_date' => $this->certificate_issue_date?->toDateString(),
            'certificate_expiry_date' => $this->certificate_expiry_date?->toDateString(),

            'has_certificate' => $this->hasCertificate(),
            'certificate_original_name' => $this->certificate_original_name,
            'certificate_mime_type' => $this->certificate_mime_type,
            'certificate_size' => $this->certificate_size,
            'certificate_file_url' => $this->hasCertificate()
                ? url('/api/v1/employee-training/'.$this->id.'/file')
                : null,

            'certificate_expiry_state' => $state,
            'warning_days' => $this->warningDays(),
            'days_until_expiry' => $days,
            // The scan's own "has it been said?" marker — exposed so a
            // screen can explain why nothing has arrived yet rather than
            // silently showing an empty warning list.
            'expiry_notified_at' => $this->expiry_notified_at,

            'created_by' => $this->created_by,

            // Record state, not reader permission — see the class note.
            'is_editable' => $this->isOpen(),
            'is_terminal' => $this->isTerminal(),
            'is_completable' => ! in_array($this->status, [
                EmployeeTraining::STATUS_COMPLETED,
                EmployeeTraining::STATUS_CANCELLED,
                EmployeeTraining::STATUS_FAILED,
                EmployeeTraining::STATUS_EXPIRED,
            ], true),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
