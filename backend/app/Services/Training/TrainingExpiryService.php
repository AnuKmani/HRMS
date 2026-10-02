<?php

namespace App\Services\Training;

use App\Events\EmployeeTrainingExpired;
use App\Events\EmployeeTrainingExpiring;
use App\Models\EmployeeTraining;
use App\Services\Expiry\ScansExpiringRows;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The server's own answer to "is this person still certified?".
 *
 * **The algorithm is DocumentExpiryService's** — it comes from
 * {@see ScansExpiringRows} rather than being written out again: select the
 * ids that need acting on, then act on each one inside its own transaction
 * under `lockForUpdate()`, so a second run and a second worker both find
 * nothing to do. The scheduler registration is one more line beside the
 * document scan's, not a second mechanism.
 *
 * What is deliberately *not* shared is what has to differ:
 *
 *  - **Only `completed` rows lapse.** A certificate lapsing does not
 *    un-take the course: the completion date, the result and the trainer
 *    all stay on the record, and `expired` says what happened to the
 *    *paper*. A document, by contrast, is entirely its own validity, so it
 *    lapsed from `pending` and `valid` alike.
 *
 *  - **There is one warning window, not one per row.** Documents take
 *    theirs from `document_types.expiry_warning_days` because a passport
 *    and a labour card genuinely need different notice. A training
 *    certificate is not a document type, and adding a per-program window
 *    would mean a second number that has to be kept in step with the first
 *    — so both the scan and the chip read
 *    `config('hrms.expiry.default_warning_days')` and there is exactly one
 *    answer to "when does expiring soon start?" for this module.
 *
 *  - **Cancelled and failed rows never warn.** There is no certificate to
 *    lapse, and reporting one would be a bug that reads as a fact.
 *
 * `expiry_notified_at` carries the same meaning it does on a document: the
 * idempotency marker. Stamped in the same transaction that raises the
 * event, cleared whenever the certificate is re-dated — so a re-issued card
 * gets a fresh warning instead of inheriting the old one's silence.
 *
 * Nothing here decides *who is told*. The two events are hooks for Phase 12;
 * FCM is not wired up by design, because a reminder nobody receives is worse
 * than no reminder — the system would believe it was sent.
 */
class TrainingExpiryService
{
    use ScansExpiringRows;

    /**
     * The ids whose certificate date has already passed.
     *
     * @return iterable<int>
     */
    protected function expirableIds(): iterable
    {
        return EmployeeTraining::query()
            ->where('status', EmployeeTraining::STATUS_COMPLETED)
            ->whereNotNull('certificate_expiry_date')
            ->where('certificate_expiry_date', '<', Carbon::today()->toDateString())
            ->orderBy('id')
            ->pluck('id');
    }

    protected function attemptExpire(int $id): bool
    {
        return (bool) DB::transaction(function () use ($id) {
            $training = EmployeeTraining::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($training === null) {
                return false;
            }

            // Re-read from the locked row: the window between the select
            // above and here is exactly where a re-dated certificate or a
            // cancellation can land.
            if ($training->status !== EmployeeTraining::STATUS_COMPLETED) {
                return false;
            }

            if ($training->certificate_expiry_date === null
                || $training->certificate_expiry_date->startOfDay()->gte(Carbon::today())) {
                return false;
            }

            $training->status = EmployeeTraining::STATUS_EXPIRED;
            // One marker for both messages, exactly as on a document: a
            // certificate that has lapsed never needs warning about too,
            // and a single column means the second pass cannot find it.
            $training->expiry_notified_at = Carbon::now();
            $training->save();

            event(new EmployeeTrainingExpired($training));

            return true;
        });
    }

    /**
     * The ids inside the warning window that have not been reported yet.
     *
     * @return iterable<int>
     */
    protected function warnableIds(): iterable
    {
        $today = Carbon::today();

        return EmployeeTraining::query()
            ->where('status', EmployeeTraining::STATUS_COMPLETED)
            ->whereNotNull('certificate_expiry_date')
            ->whereNull('expiry_notified_at')
            ->where('certificate_expiry_date', '>=', $today->toDateString())
            // Bounded in SQL as well as in PHP: with one shared window the
            // horizon is a single config value, so there is no reason to
            // read rows years away in order to reject them in PHP.
            ->where('certificate_expiry_date', '<=', $today->copy()->addDays($this->warningDays())->toDateString())
            ->orderBy('id')
            ->pluck('id');
    }

    protected function attemptWarn(int $id): bool
    {
        return (bool) DB::transaction(function () use ($id) {
            $training = EmployeeTraining::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($training === null || $training->expiry_notified_at !== null) {
                return false;
            }

            if ($training->status !== EmployeeTraining::STATUS_COMPLETED) {
                return false;
            }

            if ($training->certificate_expiry_date === null) {
                return false;
            }

            $threshold = Carbon::today()->addDays($this->warningDays());

            if ($training->certificate_expiry_date->startOfDay()->gt($threshold)) {
                return false;
            }

            $training->expiry_notified_at = Carbon::now();
            $training->save();

            event(new EmployeeTrainingExpiring(
                $training,
                $training->certificate_expiry_date->toDateString(),
            ));

            return true;
        });
    }

    /**
     * The one warning window this module has.
     *
     * Read through the model rather than inlined, so the scan, the list
     * filter and the Flutter chip are all asking the same object the same
     * question — see EmployeeTraining::warningDays() for why it is a single
     * config value rather than a per-program column.
     */
    private function warningDays(): int
    {
        return max(0, (int) config('hrms.expiry.default_warning_days', 30));
    }
}
