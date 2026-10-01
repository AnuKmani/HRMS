<?php

namespace App\Jobs;

use App\Services\Documents\DocumentExpiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Expire documents whose date has passed, and warn about the ones about to.
 *
 * Runs from the scheduler (routes/console.php), never from Flutter. An
 * employment file lapsing is a fact about the calendar: it has to be true
 * whether or not anybody opens the app, on a phone with no signal, on a day
 * nobody logs in. The server's clock and the server's queue do it, so the
 * outcome is the same for everybody.
 *
 * Idempotent, in three independent ways — deliberately the same three
 * {@see EnforceSickCertificateDeadlines} uses, because "run it twice and
 * only happen once" is a property worth having rather than hoping for:
 *
 *  1. **The queries are the guard.** Expired rows no longer match the
 *     expire select, and warned rows have a non-null `expiry_notified_at`,
 *     so a second run reads nothing and says nothing.
 *  2. **Each row is re-checked under `lockForUpdate()`.** Two workers that
 *     selected the same id will not both report it: the second waits,
 *     re-reads, sees the marker, and moves on.
 *  3. **`ShouldBeUnique`** stops an overlapping run from even being
 *     dispatched while one is in flight.
 *
 * `$tries` is 1 rather than the sick job's 3: every row is its own
 * transaction, so a poisoned row costs one document rather than the whole
 * scan, and a retry re-selecting rows that were already converted would
 * only be re-running work that is done. The uniqueness TTL is half an hour
 * — longer than any plausible run, short enough that a leaked lock costs
 * at most one skipped scan rather than a permanently blind one.
 *
 * The events it raises are hooks for a later notification phase. This job
 * delivers nothing, queues nothing and sends nothing: FCM is not wired up,
 * and a "reminder" nobody receives is worse than no reminder because the
 * system believes it was sent.
 */
class ScanDocumentExpiries implements ShouldBeUnique, ShouldQueue
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
    public function handle(DocumentExpiryService $service): array
    {
        return $service->scan();
    }
}
