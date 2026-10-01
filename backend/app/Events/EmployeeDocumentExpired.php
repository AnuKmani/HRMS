<?php

namespace App\Events;

use App\Models\EmployeeDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An employment document's expiry date has passed.
 *
 * Raised by the scheduled scan in the same transaction that flips the row
 * from `pending`/`valid` to `expired` — so the event and the status change
 * are one fact, and a listener that reads the row back sees what the event
 * describes rather than yesterday's status.
 *
 * Like {@see EmployeeDocumentExpiring} this is a hook, not a notification.
 * Nothing in Phase 10 listens to it; the notification phase will, without
 * touching DocumentExpiryService. No channel, no recipient list, no FCM —
 * a queued push that the application cannot actually deliver is worse than
 * silence, because it reads as "sent".
 *
 * Raised whether the document was ever in its warning window or not: a file
 * uploaded with an already-past expiry date, or one whose date moved past
 * while the scheduler was down, still lapsed, and the scan reports the fact
 * exactly once either way.
 */
class EmployeeDocumentExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EmployeeDocument $document) {}
}
