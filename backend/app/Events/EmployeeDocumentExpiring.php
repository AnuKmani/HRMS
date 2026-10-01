<?php

namespace App\Events;

use App\Models\EmployeeDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An employment document has entered its expiry warning window.
 *
 * Raised by the scheduled scan (ScanDocumentExpiries), once per document and
 * never twice: `employee_documents.expiry_notified_at` is stamped in the same
 * transaction that raises this, so a second run finds the marker already set
 * and says nothing. Re-dating the document clears it, which is what makes a
 * re-dated passport get a fresh warning rather than inheriting the old one's
 * silence.
 *
 * **This is a hook, not a notification.** Nothing in Phase 10 listens to it.
 * The notification phase will attach "tell the employee, tell HR" — and the
 * FCM phase will attach delivery — and neither will have to touch
 * DocumentExpiryService to do it. There is deliberately no recipient list
 * and no channel on this class: inventing one here would be exactly the
 * fake notification the specification rules out (scope item J).
 *
 * `expiresOn` is carried alongside the document rather than read off it by
 * a future listener because the date is the entire message: "in 90 days" and
 * "in 3" are the same event and a different urgency.
 *
 * ShouldDispatchAfterCommit rather than plain Dispatchable for the reason
 * LeaveConvertedToLop gives: a listener that read the row while the scan's
 * transaction was still open would see the pre-marker state and could not
 * tell whether the warning had already been raised.
 */
class EmployeeDocumentExpiring implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly EmployeeDocument $document,
        public readonly string $expiresOn,
    ) {}
}
