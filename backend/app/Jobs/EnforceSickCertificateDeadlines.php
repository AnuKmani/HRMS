<?php

namespace App\Jobs;

use App\Models\LeaveRequest;
use App\Services\Leave\LeaveRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Turn overdue, certificate-less sick leave into Loss of Pay.
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
     * @return int how many requests were converted
     */
    public function handle(LeaveRequestService $service): int
    {
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
