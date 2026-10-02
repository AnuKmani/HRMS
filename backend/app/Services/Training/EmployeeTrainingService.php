<?php

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\TrainingProgram;
use App\Models\User;
use App\Services\Documents\EmployeeDocumentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * The only thing that writes an enrolment, and what it refuses.
 *
 * Every transition in the training lifecycle goes through here — assign,
 * correct, complete, cancel, attach a certificate — so "which sequence can
 * turn an enrolment into an expired certificate, and what has to be true at
 * each step" has exactly one answer. A controller that set `status` directly
 * would skip the rules *and* the audit seam: the brief asks for these
 * operations to be service methods precisely so Phase 12 can attach logging
 * to one place rather than to four endpoints.
 *
 * Five decisions, each made once:
 *
 *  - **A place on a course cannot be booked twice.** Enrolling the same
 *    person on the same program while an earlier enrolment is still live is
 *    a 409, not a 422: the payload is perfectly valid, the *world* is in the
 *    way. Recertification is still possible — a completed course may be sat
 *    again, because the earlier row is no longer live.
 *
 *  - **Completion is where the certificate's dates are settled.** If the
 *    program has a validity and the certificate was issued with a date but
 *    no expiry, the expiry is computed here rather than by the screen, so
 *    two clients cannot compute two different dates for one card.
 *
 *  - **A program that requires a certificate cannot be completed without
 *    one.** An issue date or a file — the brief's rule, asked of the service
 *    rather than of the form, so a 422 says the same thing whichever client
 *    sent it.
 *
 *  - **Changing a certificate date clears the warning marker.** Without it
 *    a re-issued card inherits the silence of the window it has already
 *    left and the scan never mentions it again — the exact bug
 *    EmployeeDocumentService avoids by clearing `expiry_notified_at` on a
 *    re-dated document.
 *
 *  - **The bytes go through {@see EmployeeDocumentStore}.** Not a second
 *    store: the same private disk, the same UUID name, the same sanitizer
 *    for images, the same `%PDF-` sniff for PDFs, the same five-way
 *    validation. A training certificate is an employee document, and giving
 *    it its own storage layer would mean a second set of path containment,
 *    sniffing and tests to keep in step with the first.
 *
 * Transitions answer **409 naming the state** rather than 403, because
 * "already completed" and "you are not allowed" are different sentences and
 * only one of them tells the caller what to do next.
 */
class EmployeeTrainingService
{
    public function __construct(private readonly EmployeeDocumentStore $files) {}

    /**
     * Put somebody on a course.
     *
     * @param  array<string, mixed>  $data
     */
    public function assign(User $actor, Employee $employee, array $data): EmployeeTraining
    {
        /** @var TrainingProgram $program */
        $program = TrainingProgram::query()->findOrFail($data['training_program_id']);

        if (! $program->isActive()) {
            abort(409, 'That training program has been retired. Reactivate it before assigning it.');
        }

        $enrollmentDate = (string) ($data['enrollment_date'] ?? Carbon::today()->toDateString());

        // Two questions, asked in this order because they have two
        // different answers.
        //
        // The first is the unique index in `employee_trainings` asked
        // *before* it can fire: the index allows a completed course to be
        // sat again (its row is a different enrolment date) but not two
        // rows on the same day, and letting the database be the one to say
        // so would turn a perfectly reasonable-sounding request into a 500
        // instead of a sentence.
        if (EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('training_program_id', $program->id)
            ->where('enrollment_date', $enrollmentDate)
            ->exists()) {
            abort(409, 'That person is already enrolled on this course for that date.');
        }

        // The second is the real rule: an open place on a course cannot be
        // held twice, on *any* date. Asked without the date, because two
        // live rows for one person on one programme would be two seats and
        // one attendee.
        if (EmployeeTraining::query()
            ->where('employee_id', $employee->id)
            ->where('training_program_id', $program->id)
            ->live()
            ->exists()) {
            abort(409, 'That person already has a place on this course.');
        }

        $training = new EmployeeTraining([
            'employee_id' => $employee->id,
            'training_program_id' => $program->id,
            'enrollment_date' => $enrollmentDate,
            'training_date' => $data['training_date'] ?? null,
            'trainer' => $data['trainer'] ?? null,
            'status' => $data['status'] ?? EmployeeTraining::STATUS_ENROLLED,
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $actor->id,
        ]);

        $training->save();

        return $training;
    }

    /**
     * Correct the details of an enrolment that has not reached an outcome.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, EmployeeTraining $training, array $data): EmployeeTraining
    {
        $this->assertEditable($training);

        $before = [
            'expiry' => $training->certificate_expiry_date?->toDateString(),
            'path' => $training->certificate_path,
        ];

        $enrollmentDate = $this->pick($data, 'enrollment_date', $training->enrollment_date);

        // Moving the enrolment to a date another row already occupies would
        // violate the same rule `assign()` enforces, and would do it as a
        // database error rather than as a sentence. The row itself is
        // excluded — this enrolment moving to *its own* date is what an
        // edit of one field looks like.
        $clash = EmployeeTraining::query()
            ->where('employee_id', $training->employee_id)
            ->where('training_program_id', $training->training_program_id)
            ->where('enrollment_date', $enrollmentDate)
            ->whereKeyNot($training->id)
            ->exists();

        if ($clash) {
            abort(409, 'That person is already enrolled on this course for that date.');
        }

        // And an open place is an open place, whatever date it carries: a
        // second *live* row for one person on one programme is two seats
        // and one attendee, and no amount of editing a date changes that.
        $liveClash = EmployeeTraining::query()
            ->where('employee_id', $training->employee_id)
            ->where('training_program_id', $training->training_program_id)
            ->whereKeyNot($training->id)
            ->live()
            ->exists();

        if ($liveClash) {
            abort(409, 'That person already has a place on this course.');
        }

        $training->enrollment_date = $enrollmentDate;
        $training->training_date = $this->pick($data, 'training_date', $training->training_date);
        $training->trainer = $this->pick($data, 'trainer', $training->trainer);
        $training->remarks = $this->pick($data, 'remarks', $training->remarks);

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $training->status = $data['status'];
        }

        if (array_key_exists('result', $data)) {
            $training->result = $data['result'];
        }

        $training->certificate_number = $this->pick(
            $data,
            'certificate_number',
            $training->certificate_number,
        );
        $training->certificate_issue_date = $this->pick(
            $data,
            'certificate_issue_date',
            $training->certificate_issue_date,
        );
        $training->certificate_expiry_date = $this->pick(
            $data,
            'certificate_expiry_date',
            $training->certificate_expiry_date,
        );

        if ($file = $this->uploadedFile($data)) {
            $previous = $training->certificate_path;
            $this->attach($training, $file);
            $this->files->remove($previous);
        }

        // A new date is a new warning, and a new file is a new certificate —
        // either one must give the scan something to notice next run.
        if ($before['expiry'] !== $training->certificate_expiry_date?->toDateString()
            || $before['path'] !== $training->certificate_path) {
            $training->expiry_notified_at = null;
        }

        $training->save();

        return $training;
    }

    /**
     * Record that somebody finished the course.
     *
     * @param  array<string, mixed>  $data
     */
    public function complete(User $actor, EmployeeTraining $training, array $data): EmployeeTraining
    {
        if ($training->status === EmployeeTraining::STATUS_CANCELLED) {
            abort(409, 'A cancelled enrolment cannot be completed. Enrol them again.');
        }

        if ($training->status === EmployeeTraining::STATUS_FAILED) {
            abort(409, 'That enrolment has already been recorded as failed.');
        }

        if ($training->status === EmployeeTraining::STATUS_COMPLETED) {
            abort(409, 'That training has already been marked complete.');
        }

        $program = $training->trainingProgram;

        $training->completion_date = $data['completion_date'] ?? Carbon::today()->toDateString();
        $training->training_date = $data['training_date']
            ?? $training->training_date
            ?? $training->completion_date;
        $training->result = $this->pick($data, 'result', $training->result);
        $training->trainer = $this->pick($data, 'trainer', $training->trainer);
        $training->remarks = $this->pick($data, 'remarks', $training->remarks);

        $training->certificate_number = $this->pick(
            $data,
            'certificate_number',
            $training->certificate_number,
        );
        $training->certificate_issue_date = $this->pick(
            $data,
            'certificate_issue_date',
            $training->certificate_issue_date,
        );
        $training->certificate_expiry_date = $this->pick(
            $data,
            'certificate_expiry_date',
            $training->certificate_expiry_date,
        );

        if ($file = $this->uploadedFile($data)) {
            $previous = $training->certificate_path;
            $this->attach($training, $file);
            $this->files->remove($previous);
        }

        // The expiry the *program* promises is applied only when nobody
        // supplied one: a card the instructor dated by hand wins, and a
        // program with no validity issues a certificate that never lapses
        // rather than one that lapses immediately.
        if ($training->certificate_expiry_date === null
            && $training->certificate_issue_date !== null
            && ($validity = $program?->certificateValidityDays()) !== null) {
            $training->certificate_expiry_date = $training->certificate_issue_date
                ->copy()
                ->addDays($validity);
        }

        if ($program?->certificate_required && $training->certificate_issue_date === null) {
            abort(422, 'This training program requires a certificate: record its issue date or attach the file.');
        }

        $training->status = EmployeeTraining::STATUS_COMPLETED;
        // A fresh completion is a fresh fact: anything the scan decided
        // about the *previous* certificate no longer applies to this one.
        $training->expiry_notified_at = null;
        $training->save();

        return $training;
    }

    /**
     * Take an enrolment off the books without inventing a result.
     *
     * `cancelled` rather than a deletion: who was booked on what, and that
     * it did not happen, is a fact a later audit wants, and a row that
     * vanished would leave the seat unexplained.
     *
     * @param  array<string, mixed>  $data
     */
    public function cancel(User $actor, EmployeeTraining $training, array $data = []): EmployeeTraining
    {
        if ($training->status === EmployeeTraining::STATUS_CANCELLED) {
            abort(409, 'That enrolment is already cancelled.');
        }

        if ($training->status === EmployeeTraining::STATUS_COMPLETED) {
            abort(409, 'A completed training cannot be cancelled. Correct the record instead.');
        }

        if ($training->status === EmployeeTraining::STATUS_EXPIRED) {
            abort(409, 'That enrolment has already lapsed.');
        }

        $training->status = EmployeeTraining::STATUS_CANCELLED;

        $remarks = $data['remarks'] ?? null;
        if ($remarks !== null && $remarks !== '') {
            $training->remarks = $remarks;
        }

        $training->save();

        return $training;
    }

    /**
     * May this enrolment still be corrected?
     *
     * Cancelled and failed are decided outcomes: the attempt happened and
     * its result is on the record, so there is nothing left for an edit to
     * say — and letting one be walked backwards would erase the record of
     * the attempt.
     */
    private function assertEditable(EmployeeTraining $training): void
    {
        if ($training->status === EmployeeTraining::STATUS_CANCELLED) {
            abort(409, 'A cancelled enrolment cannot be edited. Enrol them again.');
        }

        if ($training->status === EmployeeTraining::STATUS_FAILED) {
            abort(409, 'A failed enrolment cannot be edited. Enrol them again.');
        }
    }

    private function uploadedFile(array $data): ?UploadedFile
    {
        $file = $data['file'] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }

    private function attach(EmployeeTraining $training, UploadedFile $file): void
    {
        $stored = $this->files->store($training->employee_id, $file);

        $training->certificate_path = $stored['path'];
        $training->certificate_original_name = $stored['original_name'];
        $training->certificate_mime_type = $stored['mime_type'];
        $training->certificate_size = $stored['file_size'];
    }

    /**
     * `array_key_exists` semantics for a partial update: a key that was sent
     * as null clears the column, a key that was not sent at all leaves it
     * alone. Reading `$data['x'] ?? $current` would make the two
     * indistinguishable and an edit form could never empty a field.
     */
    private function pick(array $data, string $key, mixed $current): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : $current;
    }
}
