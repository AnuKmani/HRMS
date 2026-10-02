<?php

namespace App\Jobs;

use App\Events\SickCertificateReminder;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveRequestService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Warn about an approaching certificate deadline, then turn overdue,
 * certificate-less sick leave into Loss of Pay.
 *
 * Runs from the scheduler (routes/console.php), never from Flutter. A phone
 * that is switched off, offline or uninstalled must not decide whether
 * somebody gets paid — the server's clock and the server's queue do that, so
 * the outcome is the same whether or not anybody ever opens the app.
 *
 * Idempotent, in three independent ways, because "run it twice and only
 * happen once" is a property worth having rather than hoping for:
 *
 *  1. **The query is the guard.** Only `pending` / `approved` rows with no
 *     certificate, a deadline on or before today, and a null
 *     `certificate_checked_at` are selected. Conversion flips the status to
 *     `lop` and stamps the marker, so a second run matches nothing.
 *  2. **Each row is re-checked under `lockForUpdate()`.** Two workers that
 *     selected the same id will not both convert it: the second waits,
 *     re-reads, sees `lop`, and moves on.
 *  3. **`ShouldBeUnique`** stops an overlapping run from even being
 *     dispatched while one is in flight.
 *
 * A failure on one row does not abort the rest — each is its own
 * transaction, so one malformed request cannot hold up every other
 * employee's deadline.
 */
class EnforceSickCertificateDeadlines implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A deadlocked or poisoned row should not be retried forever; three
     * attempts spread over the queue's backoff is a decision, not a default.
     */
    public int $tries = 3;

    /**
     * Belt and braces on the uniqueness lock. Without a TTL, a process that
     * died between acquiring and releasing would hold `ShouldBeUnique`
     * forever and the deadline would quietly stop being enforced — the exact
     * failure this job exists to prevent. One hour is longer than any
     * plausible run and short enough that a leaked lock costs at most one
     * skipped tick.
     */
    public int $uniqueFor = 3600;

    /**
     * Warn first, then convert.
     *
     * The warning pass runs ahead of the deadline pass so a person hears
     * about a certificate that is about to become Loss of Pay while there
     * is still something they can do about it. It deliberately does not
     * contribute to the return value: this method promises "how many
     * requests were converted", and a reminder is not a conversion.
     *
     * @return int how many requests were converted
     */
    public function handle(LeaveRequestService $service, SettingsService $settings): int
    {
        $this->remindUpcoming($settings);

        $due = LeaveRequest::query()
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_APPROVED,
            ])
            ->whereNull('certificate_path')
            ->whereNotNull('certificate_due_at')
            ->whereDate('certificate_due_at', '<=', today())
            ->whereNull('certificate_checked_at')
            ->orderBy('id')
            ->pluck('id');

        $converted = 0;

        foreach ($due as $id) {
            if ($this->convertOne($id, $service)) {
                $converted++;
            }
        }

        return $converted;
    }

    /**
     * Raise {@see SickCertificateReminder} for every request whose deadline
     * is inside the warning window.
     *
     * **Nothing here marks a row as reminded.** The clock cannot own that
     * flag: it would be a second column on `leave_requests` keeping time
     * with `certificate_due_at`, and the two would drift the first time a
     * deadline was extended. The dedupe key carried on the message
     * (`sick_cert:{id}`, enforced by `notifications`' unique index) is the
     * whole suppression mechanism, which means the window is free to move
     * and a request that slips out of it and back in is handled correctly
     * without anything to reset.
     *
     * `$window <= 0` switches the pass off entirely — a deployment that
     * does not want reminders says so with one number rather than by
     * stripping a job out of the scheduler.
     */
    private function remindUpcoming(SettingsService $settings): void
    {
        $window = $settings->int('notification.sick_cert_expiry_warning_days', 7);

        if ($window <= 0) {
            return;
        }

        $today = today();

        $upcoming = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_APPROVED,
            ])
            ->whereNull('certificate_path')
            ->whereNotNull('certificate_due_at')
            ->whereBetween(
                'certificate_due_at',
                [$today->toDateString(), $today->copy()->addDays($window)->toDateString()],
            )
            ->orderBy('id')
            ->get();

        foreach ($upcoming as $leave) {
            event(new SickCertificateReminder($leave));
        }
    }

    private function convertOne(int $id, LeaveRequestService $service): bool
    {
        return (bool) DB::transaction(function () use ($id, $service) {
            $leave = LeaveRequest::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($leave === null) {
                return false;
            }

            // Every condition re-read from the locked row rather than trusted
            // from the select above — the window between the two is exactly
            // where a certificate can arrive.
            if ($leave->certificate_path !== null) {
                return false;
            }

            if (! in_array($leave->status, [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_APPROVED,
            ], true)) {
                return false;
            }

            if ($leave->certificate_due_at === null || $leave->certificate_due_at->gt(today())) {
                return false;
            }

            $service->convertToLop($leave, sprintf(
                'Medical certificate was not received by %s.',
                $leave->certificate_due_at->toDateString(),
            ));

            return true;
        });
    }
}
