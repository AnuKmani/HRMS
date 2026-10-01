<?php

namespace App\Services\Documents;

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * The only thing that writes an employee document, and what it refuses.
 *
 * Four decisions, each made once:
 *
 *  - **Upload starts at `pending`, always.** Not `valid` — not even when HR
 *    uploads it themselves. A document whose job is to prove somebody's
 *    identity gains nothing from being trusted the moment it arrived, and
 *    a status that meant "whoever uploaded this said so" would be a status
 *    that says nothing. Verification is a separate act with its own
 *    permission, and the onboarding checklist reads `pending` as "waiting
 *    for HR" rather than as "done".
 *
 *  - **Changing evidence withdraws the verification.** Editing a typo in
 *    `notes` leaves a verified document verified; changing the file, the
 *    number or either date sends it back to `pending` and clears
 *    `verified_at`/`verified_by`. Otherwise a document could be signed off
 *    on Monday and quietly replaced on Tuesday while keeping Monday's
 *    signature — which is exactly the substitution a verification exists to
 *    prevent.
 *
 *  - **Rejecting records who rejected it.** `verified_by` is the reviewer
 *    of the decision (set by both verify and reject); `verified_at` is
 *    stamped only on acceptance, because a rejected document has not been
 *    verified. `rejection_reason` is required — "rejected" with no reason
 *    is a dead end for the employee who has to fix it.
 *
 *  - **Archiving is not deleting.** `archive()` moves the row out of the
 *    active list and stamps `archived_at`; it does not touch the file and
 *    has no counterpart that does. An employment file is historical fact,
 *    and no endpoint in this application physically removes one.
 *
 * State — is this document in a state that may be verified, is it already
 * archived — is asked here and answered with a 409 naming the state, not
 * with a 403: "already verified" and "you are not allowed" are different
 * sentences and only one of them tells the caller what to do next.
 */
class EmployeeDocumentService
{
    public function __construct(private readonly EmployeeDocumentStore $files) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, Employee $employee, array $data): EmployeeDocument
    {
        /** @var DocumentType $type */
        $type = DocumentType::query()->findOrFail($data['document_type_id']);

        $document = new EmployeeDocument([
            'employee_id' => $employee->id,
            'document_type_id' => $type->id,
            'document_number' => $data['document_number'] ?? null,
            'issue_date' => $data['issue_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => EmployeeDocument::STATUS_PENDING,
            'uploaded_by' => $actor->id,
        ]);

        if ($file = $this->uploadedFile($data)) {
            $this->attach($document, $employee, $file);
        }

        $document->save();

        return $document;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Employee $employee, EmployeeDocument $document, array $data): EmployeeDocument
    {
        if ($document->isArchived()) {
            abort(409, 'This document is archived. Restore it before editing.');
        }

        $before = [
            'number' => $document->document_number,
            'issue' => $document->issue_date?->toDateString(),
            'expiry' => $document->expiry_date?->toDateString(),
            'path' => $document->path,
        ];

        $document->document_number = $data['document_number'] ?? $document->document_number;
        $document->issue_date = array_key_exists('issue_date', $data) ? ($data['issue_date'] ?? null) : $document->issue_date;
        $document->expiry_date = array_key_exists('expiry_date', $data) ? ($data['expiry_date'] ?? null) : $document->expiry_date;
        $document->notes = array_key_exists('notes', $data) ? ($data['notes'] ?? null) : $document->notes;

        if ($file = $this->uploadedFile($data)) {
            $previous = $document->path;
            $this->attach($document, $employee, $file);

            // The bytes this row points at changed, so the old ones must not
            // linger on a disk with nothing pointing at them.
            $this->files->remove($previous);
        }

        $touched = $before['number'] !== $document->document_number
            || $before['issue'] !== $document->issue_date?->toDateString()
            || $before['expiry'] !== $document->expiry_date?->toDateString()
            || $before['path'] !== $document->path;

        if ($touched && ! $document->isArchived()) {
            $document->status = EmployeeDocument::STATUS_PENDING;
            $document->verified_at = null;
            $document->verified_by = null;
        }

        // A new date is a new warning: without this a re-dated document
        // inherits the silence of the window it has already left, and the
        // scan would never mention it again.
        if ($before['expiry'] !== $document->expiry_date?->toDateString()) {
            $document->expiry_notified_at = null;
        }

        $document->save();

        return $document;
    }

    public function verify(User $actor, EmployeeDocument $document): EmployeeDocument
    {
        if ($document->isArchived()) {
            abort(409, 'An archived document cannot be verified.');
        }

        if ($document->status === EmployeeDocument::STATUS_VALID) {
            abort(409, 'That document has already been verified.');
        }

        $document->status = $this->statusFor($document);
        $document->verified_at = Carbon::now();
        $document->verified_by = $actor->id;
        $document->rejection_reason = null;
        $document->save();

        return $document;
    }

    public function reject(User $actor, EmployeeDocument $document, string $reason): EmployeeDocument
    {
        if ($document->isArchived()) {
            abort(409, 'An archived document cannot be rejected.');
        }

        if ($document->status === EmployeeDocument::STATUS_REJECTED) {
            abort(409, 'That document has already been rejected.');
        }

        $document->status = EmployeeDocument::STATUS_REJECTED;
        $document->rejection_reason = $reason;
        $document->verified_by = $actor->id;
        $document->verified_at = null;
        $document->save();

        return $document;
    }

    /**
     * Take a document out of the active list — and nothing else.
     *
     * The file stays exactly where it is. "Remove from circulation" and
     * "destroy the evidence" are two acts, only the first has a route, and
     * an operator who genuinely needs the second can reach the disk.
     */
    public function archive(User $actor, EmployeeDocument $document): EmployeeDocument
    {
        if ($document->isArchived()) {
            abort(409, 'That document is already archived.');
        }

        $document->status = EmployeeDocument::STATUS_ARCHIVED;
        $document->archived_at = Carbon::now();
        $document->save();

        return $document;
    }

    /**
     * What a freshly accepted document should be called.
     *
     * `valid` unless its date has already passed — accepting an expired
     * passport does not make it unexpired, and stamping it `valid` would
     * hand the onboarding checklist a "satisfied" it cannot defend. The
     * scan would catch it on the next run; saying it now saves the
     * half-day in between.
     */
    private function statusFor(EmployeeDocument $document): string
    {
        if ($document->expiry_date !== null && $document->expiry_date->startOfDay()->lt(Carbon::today())) {
            return EmployeeDocument::STATUS_EXPIRED;
        }

        return EmployeeDocument::STATUS_VALID;
    }

    private function uploadedFile(array $data): ?UploadedFile
    {
        $file = $data['file'] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }

    private function attach(EmployeeDocument $document, Employee $employee, UploadedFile $file): void
    {
        $stored = $this->files->store($employee->id, $file);

        $document->path = $stored['path'];
        $document->original_name = $stored['original_name'];
        $document->mime_type = $stored['mime_type'];
        $document->file_size = $stored['file_size'];
    }
}
