<?php

namespace App\Jobs;

use App\Services\Training\TrainingExpiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Expire training certificates whose date has passed, and warn about the
 * ones about to.
 *
 * Runs from the scheduler (routes/console.php), never from Flutter, and for
 * the identical reason {@see ScanDocumentExpiries} gives: a certificate
 * lapsing is a fact about the calendar, and a phone that is switched off,
 * offline or uninstalled does not get to decide whether a man may still
 * work at height. The server's clock and the server's queue do it, so the
 * outcome is the same for everybody.
 *
 * **Idempotency is the same three guarantees the document scan gives, and it
 * is the same guarantees for the same reasons:**
 *
 *  1. **The queries are the guard.** `completed` rows already expired no
 *     longer match the expire select, and warned rows carry a non-null
 *     `expiry_notified_at`, so a second run reads nothing and says nothing.
 *  2. **Each row is re-checked under `lockForUpdate()`.** Two workers that
 *     selected the same id will not both report it: the second waits,
 *     re-reads, sees the marker, and moves on.
 *  3. **`ShouldBeUnique`** stops an overlapping run from even being
 *     dispatched while one is in flight.
 *
 * `$tries` is 1 rather than the sick leave job's 3: every row is its own
 * transaction, so a poisoned row costs one enrolment rather than the whole
 * scan, and a retry re-selecting rows that were already converted would only
 * be re-running completed work. The uniqueness TTL is half an hour — longer
 * than any plausible run, short enough that a leaked lock costs at most one
 * skipped scan rather than a permanently blind one.
 *
 * **This job delivers nothing.** It raises {@see EmployeeTrainingExpired}
 * and {@see EmployeeTrainingExpiring}, both hooks for Phase 12. There is no
 * FCM subscriber and no queue of messages behind it: a "reminder" nobody
 * receives is worse than no reminder, because the system believes it was
 * sent.
 */
class ScanTrainingExpiries implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Belt and braces on the uniqueness lock — see the class note.
     */
    public int $uniqueFor = 1800;

    /**
     * @return array{expired: int, warned: int}
     */
    public function handle(TrainingExpiryService $service): array
    {
        return $service->scan();
    }
}
