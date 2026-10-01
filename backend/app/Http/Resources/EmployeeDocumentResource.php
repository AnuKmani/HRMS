<?php

namespace App\Http\Resources;

use App\Models\EmployeeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One document in one person's employment file, in the shape the app
 * renders.
 *
 * Four things this payload is careful about:
 *
 *  - **`path` never appears.** It is exactly the detail that should not
 *    leave the server — it says where private files live and hands a
 *    reader something to guess at. `has_file` says whether there is one,
 *    and `file_url` points at the one route that will serve it, behind the
 *    policy that already governs the row. `original_name` is kept because a
 *    person recognises "passport-scan.jpg" faster than a uuid, and it is
 *    data only: it is never used to open, serve or list anything.
 *
 *  - **`status` and `expiry_state` are two different facts.** `status` is
 *    the stored decision — somebody verified this, or the scan marked it
 *    lapsed. `expiry_state` is computed from the date *right now* by
 *    EmployeeDocument::expiryState(), using this type's own warning window,
 *    and is authoritative on the server: Flutter renders it and never
 *    derives it. A `valid` document whose date passed yesterday reports
 *    `expiry_state = expired` whether or not the scheduler has caught up,
 *    so a lagging cron cannot make the API tell a lie.
 *
 *  - **`days_until_expiring` is signed.** Negative means already past; the
 *    sign carries the meaning so no caller has to pair the number with its
 *    own date comparison to know which way it points.
 *
 *  - **`file_url` is an API route, not a public one.** It needs the same
 *    bearer token as everything else and is answered by
 *    EmployeeDocumentPolicy; there is no signed link, no expiry on it and
 *    nothing for a browser to cache.
 *
 * `is_editable` and `can_verify` are facts about this record's state rather
 * than about the reader, for the reason SiteActivityReportResource ships
 * `is_editable` and pointedly no `can_edit`: whether *you* may act is the
 * policy's answer, asked when you try.
 */
class EmployeeDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = $this->expiryState();
        $days = $this->daysUntilExpiry();

        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($this->employee)),
            'document_type_id' => $this->document_type_id,
            'document_type' => $this->whenLoaded('documentType', fn () => new DocumentTypeResource($this->documentType)),

            'document_number' => $this->document_number,
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'notes' => $this->notes,

            'has_file' => $this->hasFile(),
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'file_url' => $this->hasFile()
                ? url('/api/v1/employee-documents/'.$this->id.'/file')
                : null,

            'status' => $this->status,
            'expiry_state' => $state,
            'warning_days' => $this->warningDays(),
            'days_until_expiry' => $days,
            // Whether the expiry window this type warns about has been
            // reached yet — the scan's own "has it been said?" marker,
            // exposed so a screen can explain why nothing has arrived yet.
            'expiry_notified_at' => $this->expiry_notified_at,

            'rejection_reason' => $this->rejection_reason,
            'uploaded_by' => $this->uploaded_by,
            'verified_at' => $this->verified_at,
            'verified_by' => $this->verified_by,
            'archived_at' => $this->archived_at,

            // Record state, not reader permission — see the class note.
            'is_editable' => ! $this->isArchived(),
            'is_pending' => $this->status === EmployeeDocument::STATUS_PENDING,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
