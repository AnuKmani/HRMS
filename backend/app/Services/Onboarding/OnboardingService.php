<?php

namespace App\Services\Onboarding;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeDocument;
use App\Models\EmployeeOnboarding;
use App\Models\OnboardingRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What an employment file is made of, and where somebody is in producing it.
 *
 * Every judgement in this class is *derived* — read from the documents, the
 * employee row and the bank record that are themselves the evidence — and
 * nothing is stored as a count. That is the whole reason the requirement
 * catalogue is a table and not nine booleans on `employees`: a checklist
 * number kept beside the data it counts goes stale the moment that data
 * changes without touching it, and a stale "3 of 8 complete" is worse than
 * no number at all because it is believed.
 *
 * The five states a requirement can be in, and why they are five rather
 * than a yes/no:
 *
 *  - `satisfied`            a verified document that has not passed its
 *                           date, every required field filled in, or a
 *                           bank account on file;
 *  - `pending_verification` the file arrived and HR has not signed it off
 *                           yet — the employee has done their part, so the
 *                           nudge is HR's;
 *  - `rejected`             HR refused what was uploaded, so somebody has
 *                           to produce something different;
 *  - `expired`              what is on file has lapsed;
 *  - `missing`              nothing has been filed at all.
 *
 * Collapsing those five into "not done" would lose exactly the distinction
 * that tells an HR desk who to chase and about what — which is the whole of
 * what scope item M asks for.
 *
 * **Completion is a 409, not a 403.** An unfinished form and a caller
 * without the right to touch it are different sentences, and only the
 * second one is about permission.
 */
class OnboardingService
{
    /**
     * The active requirement catalogue, in the order a desk expects it.
     *
     * @return Collection<int, OnboardingRequirement>
     */
    public function requirements(): Collection
    {
        return OnboardingRequirement::query()
            ->active()
            ->ordered()
            ->with('documentType')
            ->get();
    }

    /**
     * This employee's onboarding row as it stands — a staged, unsaved
     * instance when nobody has opened it yet.
     *
     * Deliberately *not* `firstOrCreate`: a GET must not write a row before
     * anybody has decided the caller may read it, and the staged instance
     * lets EmployeeOnboardingPolicy answer from the employee id alone. The row is
     * materialised by {@see self::update()} when somebody actually changes
     * something.
     */
    public function recordFor(Employee $employee): EmployeeOnboarding
    {
        return $employee->onboarding
            ?? new EmployeeOnboarding([
                'employee_id' => $employee->id,
                'status' => EmployeeOnboarding::STATUS_DRAFT,
            ]);
    }

    /**
     * Every active requirement and the state this employee's file puts it
     * in, in catalogue order.
     *
     * One query for the documents rather than one per requirement: eight
     * rows times eight queries is a number nobody expects a screen to cost,
     * and grouping them by type gives the same answer with a single read.
     *
     * @return Collection<int, array{requirement: OnboardingRequirement, state: string, document: EmployeeDocument|null, missing_fields: array<int, string>}>
     */
    public function checklist(Employee $employee): Collection
    {
        $documents = $employee->documents()
            ->with('documentType')
            ->where('status', '!=', EmployeeDocument::STATUS_ARCHIVED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('document_type_id');

        return $this->requirements()
            ->map(function (OnboardingRequirement $requirement) use ($documents, $employee) {
                if ($requirement->isDocumentRequirement()) {
                    $state = OnboardingRequirement::STATE_MISSING;
                    $document = $documents->get($requirement->document_type_id)?->first();

                    if ($document !== null) {
                        $state = $this->documentState($documents->get($requirement->document_type_id));
                        $document = $this->representative($documents->get($requirement->document_type_id), $state);
                    }

                    return [
                        'requirement' => $requirement,
                        'state' => $state,
                        'document' => $document,
                        'missing_fields' => [],
                    ];
                }

                if ($requirement->kind === OnboardingRequirement::KIND_BANK) {
                    $satisfied = EmployeeBankAccount::query()
                        ->where('employee_id', $employee->id)
                        ->where('status', EmployeeBankAccount::STATUS_ACTIVE)
                        ->exists();

                    return [
                        'requirement' => $requirement,
                        'state' => $satisfied ? OnboardingRequirement::STATE_SATISFIED : OnboardingRequirement::STATE_MISSING,
                        'document' => null,
                        'missing_fields' => [],
                    ];
                }

                $missing = $this->missingFields($employee, $requirement->checkedFields());

                return [
                    'requirement' => $requirement,
                    'state' => $missing === [] ? OnboardingRequirement::STATE_SATISFIED : OnboardingRequirement::STATE_MISSING,
                    'document' => null,
                    'missing_fields' => $missing,
                ];
            });
    }

    /**
     * Mandatory requirements that are not satisfied — the list the
     * completion check refuses on, and the answer to "what is holding this
     * person up?".
     *
     * @return Collection<int, array{requirement: OnboardingRequirement, state: string, document: EmployeeDocument|null, missing_fields: array<int, string>}>
     */
    public function unmet(Employee $employee): Collection
    {
        return $this->checklist($employee)
            ->filter(fn (array $item): bool => $item['requirement']->isMandatory()
                && $item['state'] !== OnboardingRequirement::STATE_SATISFIED)
            ->values();
    }

    /**
     * Move the record along, or complete it.
     *
     * `completed` is not a status this method simply sets: it is the one
     * value with a precondition, so it is routed through the check and
     * refused with a 409 naming what is missing rather than accepted and
     * left inconsistent. Every other status is a legitimate stage for HR to
     * put somebody in, including moving back out of `completed`.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Employee $employee, array $data): EmployeeOnboarding
    {
        $status = $data['status'] ?? null;

        if ($status === EmployeeOnboarding::STATUS_COMPLETED) {
            return $this->complete($actor, $employee);
        }

        $record = $this->materialise($employee);

        if ($status !== null) {
            $record->status = $status;
        }

        if (array_key_exists('notes', $data)) {
            $record->notes = $data['notes'];
        }

        // Reopening clears the stamp rather than leaving a date that no
        // longer describes anything — `completed_at` with `status = hr_review`
        // is a record that says two different things at once.
        if ($record->status !== EmployeeOnboarding::STATUS_COMPLETED
            && $record->completed_at !== null) {
            $record->completed_at = null;
            $record->completed_by = null;
        }

        $record->started_at ??= Carbon::now();
        $record->save();

        return $record;
    }

    /**
     * Mark it done — but only once every mandatory item is actually in
     * place.
     *
     * A 409 naming the outstanding requirements, because "you are not
     * allowed" would be the wrong sentence about a form that is simply
     * unfinished, and because the caller needs the list to know what to do
     * next. The structured version of that list is `GET /onboarding/{id}`.
     */
    public function complete(User $actor, Employee $employee): EmployeeOnboarding
    {
        $unmet = $this->unmet($employee);

        if ($unmet->isNotEmpty()) {
            abort(409, sprintf(
                'Onboarding is not complete — outstanding: %s.',
                implode(', ', $unmet->map(fn (array $item): string => $item['requirement']->name)->all()),
            ));
        }

        $record = $this->materialise($employee);

        $record->status = EmployeeOnboarding::STATUS_COMPLETED;
        $record->completed_at = Carbon::now();
        $record->completed_by = $actor->id;
        $record->started_at ??= Carbon::now();
        $record->save();

        return $record;
    }

    /* -------------------------------------------------------------- filters */

    /**
     * Narrow an employee query to whoever has not filed a given
     * requirement — the cross-workforce half of "identify missing
     * mandatory documents".
     *
     * One anti-join rather than a per-employee checklist: this is the one
     * place the loop has to run over everybody, so it is the one place it
     * must not actually loop.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function constrainMissing(Builder $query, string $code): Builder
    {
        $requirement = OnboardingRequirement::query()->where('code', $code)->first();

        if ($requirement === null) {
            abort(422, sprintf('Unknown onboarding requirement "%s".', $code));
        }

        if ($requirement->isDocumentRequirement()) {
            return $query->whereDoesntHave('documents', function (Builder $documents) use ($requirement) {
                $documents
                    ->where('document_type_id', $requirement->document_type_id)
                    ->where('status', EmployeeDocument::STATUS_VALID)
                    ->where(function (Builder $dates) {
                        $dates->whereNull('expiry_date')
                            ->orWhere('expiry_date', '>=', Carbon::today()->toDateString());
                    });
            });
        }

        if ($requirement->kind === OnboardingRequirement::KIND_BANK) {
            return $query->whereDoesntHave('bankAccount', function (Builder $bank) {
                $bank->where('status', EmployeeBankAccount::STATUS_ACTIVE);
            });
        }

        return $query->where(function (Builder $employees) use ($requirement) {
            foreach ($requirement->checkedFields() as $field) {
                $employees->orWhereNull("employees.$field")
                    ->orWhere("employees.$field", '');
            }
        });
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The most recent document of this type decides the state, with one
     * exception: *any* document that still qualifies makes the requirement
     * satisfied.
     *
     * The exception matters because HR may reject the copy uploaded today
     * while a good passport from last year is still on file — the employee
     * does hold a passport, so saying "rejected" would be asking them to
     * chase something that is already solved. The rejected row is still
     * there, still `status = rejected`, and still shows in the documents
     * list; the requirement simply tells the truth about the file.
     *
     * @param  Collection<int, EmployeeDocument>|null  $documents
     */
    private function documentState(?Collection $documents): string
    {
        if ($documents === null || $documents->isEmpty()) {
            return OnboardingRequirement::STATE_MISSING;
        }

        $good = $documents->first(fn (EmployeeDocument $document): bool => $document->status === EmployeeDocument::STATUS_VALID
            && $document->expiryState() !== EmployeeDocument::EXPIRY_PASSED);

        if ($good !== null) {
            return OnboardingRequirement::STATE_SATISFIED;
        }

        $latest = $documents->first();

        return match ($latest->status) {
            EmployeeDocument::STATUS_VALID,
            EmployeeDocument::STATUS_EXPIRED => OnboardingRequirement::STATE_EXPIRED,
            EmployeeDocument::STATUS_PENDING => OnboardingRequirement::STATE_PENDING,
            EmployeeDocument::STATUS_REJECTED => OnboardingRequirement::STATE_REJECTED,
            default => OnboardingRequirement::STATE_MISSING,
        };
    }

    /**
     * Which document the checklist should show for this requirement — the
     * one the state was decided from, so the chip and the preview agree.
     *
     * @param  Collection<int, EmployeeDocument>|null  $documents
     */
    private function representative(?Collection $documents, string $state): ?EmployeeDocument
    {
        if ($documents === null || $documents->isEmpty()) {
            return null;
        }

        if ($state === OnboardingRequirement::STATE_SATISFIED) {
            return $documents->first(fn (EmployeeDocument $document): bool => $document->status === EmployeeDocument::STATUS_VALID
                && $document->expiryState() !== EmployeeDocument::EXPIRY_PASSED) ?? $documents->first();
        }

        return $documents->first();
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    private function missingFields(Employee $employee, array $fields): array
    {
        return array_values(array_filter($fields, function (string $field) use ($employee): bool {
            $value = $employee->getAttribute($field);

            return $value === null || $value === '';
        }));
    }

    private function materialise(Employee $employee): EmployeeOnboarding
    {
        $record = EmployeeOnboarding::query()->firstOrCreate(
            ['employee_id' => $employee->id],
            [
                'status' => EmployeeOnboarding::STATUS_DRAFT,
                'started_at' => Carbon::now(),
            ],
        );

        // The row is written; the employee we were handed may still be
        // holding `null` from an earlier read of `$employee->onboarding` —
        // EmployeeOnboardingPolicy's staging and UpdateOnboardingRequest's
        // both read it *before* the write to decide whether the write was
        // allowed, and Eloquent caches that null. Without this the response
        // to a successful PUT would serialise the record as it stood a
        // moment ago (`draft` after being moved to `hr_review`), which is a
        // lie about the very write the caller just made.
        $employee->setRelation('onboarding', $record);

        return $record;
    }
}
