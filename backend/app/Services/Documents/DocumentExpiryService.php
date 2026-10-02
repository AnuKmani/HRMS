<?php

namespace App\Services\Documents;

use App\Events\EmployeeDocumentExpired;
use App\Events\EmployeeDocumentExpiring;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Services\Expiry\ScansExpiringRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The server's own answer to "is this document still good?".
 *
 * Two jobs, run by the scheduled scan rather than by a screen, because both
 * are facts about the calendar and neither may depend on somebody having
 * opened the app. A phone that is switched off does not get to decide that
 * a passport has not lapsed.
 *
 *  - **Expire.** Anything whose `expiry_date` has passed and whose status is
 *    still `pending` or `valid` becomes `expired`. `rejected` and `archived`
 *    are deliberately left alone: a refused document and one taken out of
 *    circulation are already decided, and rewriting them would erase the
 *    difference between "lapsed" and "was never accepted".
 *
 *  - **Warn.** Anything inside its type's warning window raises
 *    {@see EmployeeDocumentExpiring} exactly once, marked by
 *    `expiry_notified_at`.
 *
 * **Idempotency is the query, not a flag somebody has to remember.** Pass
 * one selects rows that are not yet `expired` and turns them into rows that
 * are; pass two selects rows with a null marker and stamps it. A second run
 * therefore matches nothing on either pass, which is the same trick
 * EnforceSickCertificateDeadlines uses and the reason its test can assert
 * "0 on the second run" without any special-casing in the job. Each row is
 * then re-checked under `lockForUpdate()` so two overlapping runs cannot
 * both report the same lapse.
 *
 * The warning window comes from `document_types.expiry_warning_days` (with
 * `config('hrms.expiry.default_warning_days')` as the fallback for a type
 * nobody has configured), never from a global constant: an Emirates ID that
 * lapses in months and a passport that lapses in years should not be
 * announced with the same amount of notice.
 *
 * Nothing here decides *who is told*. The events are hooks for a later
 * notification phase; FCM is not wired up by design.
 */
class DocumentExpiryService
{
    /*
    | The two passes themselves are ScansExpiringRows: select the ids that
    | need acting on, then act on each one in its own transaction. What is
    | *not* in the trait is everything that has to differ — which statuses
    | lapse, which event class is raised, and the warning window being a
    | property of a document *type* rather than a global. See the trait for
    | why the split falls where it does.
    */
    use ScansExpiringRows;

    /**
     * The ids whose date has already passed.
     *
     * Selects rows that are not yet `expired` and turns them into rows that
     * are, which is why a second run returns an empty set and costs no
     * transactions at all.
     *
     * @return iterable<int>
     */
    protected function expirableIds(): iterable
    {
        return EmployeeDocument::query()
            ->whereIn('status', [
                EmployeeDocument::STATUS_PENDING,
                EmployeeDocument::STATUS_VALID,
            ])
            ->whereNotNull('expiry_date')
            // The column is a DATE, so a plain comparison is sargable and
            // `DATE(expiry_date) < ?` is not — one index, not a scan.
            ->where('expiry_date', '<', Carbon::today()->toDateString())
            ->orderBy('id')
            ->pluck('id');
    }

    protected function attemptExpire(int $id): bool
    {
        return (bool) DB::transaction(function () use ($id) {
            $document = EmployeeDocument::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($document === null) {
                return false;
            }

            // Every condition re-read from the locked row rather than trusted
            // from the select above — the window between the two is exactly
            // where an upload or a verification can land.
            if (! in_array($document->status, [
                EmployeeDocument::STATUS_PENDING,
                EmployeeDocument::STATUS_VALID,
            ], true)) {
                return false;
            }

            if ($document->expiry_date === null
                || $document->expiry_date->startOfDay()->gte(Carbon::today())) {
                return false;
            }

            $document->status = EmployeeDocument::STATUS_EXPIRED;
            // One marker for both messages: a document that lapsed never
            // needs to be warned about as well, and a single column means a
            // second run cannot find it on either pass.
            $document->expiry_notified_at = Carbon::now();
            $document->save();

            event(new EmployeeDocumentExpired($document));

            return true;
        });
    }

    /**
     * The ids inside their warning window that have not been reported yet.
     *
     * @return iterable<int>
     */
    protected function warnableIds(): iterable
    {
        $today = Carbon::today();

        return EmployeeDocument::query()
            ->whereIn('status', [
                EmployeeDocument::STATUS_PENDING,
                EmployeeDocument::STATUS_VALID,
            ])
            ->whereNotNull('expiry_date')
            ->whereNull('expiry_notified_at')
            ->where('expiry_date', '>=', $today->toDateString())
            // Bound the select in SQL as well as in PHP. The widest window
            // any configured type offers is a handful of rows away, and
            // asking for everything that has ever been filed would load the
            // whole table to reject all but a fortnight of it.
            ->where('expiry_date', '<=', $today->copy()->addDays($this->widestWarning())->toDateString())
            ->with('documentType')
            ->orderBy('id')
            ->pluck('id');
    }

    protected function attemptWarn(int $id): bool
    {
        return (bool) DB::transaction(function () use ($id) {
            $document = EmployeeDocument::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->with('documentType')
                ->first();

            if ($document === null || $document->expiry_notified_at !== null) {
                return false;
            }

            if (! in_array($document->status, [
                EmployeeDocument::STATUS_PENDING,
                EmployeeDocument::STATUS_VALID,
            ], true)) {
                return false;
            }

            if ($document->expiry_date === null) {
                return false;
            }

            $threshold = Carbon::today()->addDays($document->warningDays());

            if ($document->expiry_date->startOfDay()->gt($threshold)) {
                return false;
            }

            $document->expiry_notified_at = Carbon::now();
            $document->save();

            event(new EmployeeDocumentExpiring(
                $document,
                $document->expiry_date->toDateString(),
            ));

            return true;
        });
    }

    /**
     * The most generous warning window any configured type asks for.
     *
     * Read from the two or three dozen rows in `document_types` rather than
     * hardcoded, so extending a type's notice to a year widens the scan's
     * select on the next run without anybody remembering to change a
     * constant here.
     */
    private function widestWarning(): int
    {
        $fallback = max(0, (int) config('hrms.expiry.default_warning_days', 30));

        $widest = (int) DocumentType::query()
            ->max(DB::raw('COALESCE(NULLIF(expiry_warning_days, 0), '.$fallback.')'));

        return max($fallback, $widest);
    }
}
