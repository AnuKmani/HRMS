<?php

namespace App\Events;

use App\Models\EmployeeTraining;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A training certificate has entered its expiry warning window.
 *
 * Raised by the scheduled scan (ScanTrainingExpiries), once per enrolment and
 * never twice: `employee_trainings.expiry_notified_at` is stamped in the same
 * transaction that raises this, so a second run finds the marker already set
 * and says nothing. Re-dating the certificate clears it, which is what makes a
 * re-issued card get a fresh warning rather than inheriting the old one's
 * silence.
 *
 * **This is a hook, not a notification.** Nothing in Phase 11 listens to it.
 * Phase 12 will attach "tell the employee, tell HR" and the FCM phase will
 * attach delivery, and neither will have to touch TrainingExpiryService to do
 * it. There is deliberately no recipient list and no channel on this class:
 * inventing one here would be exactly the fake notification the specification
 * rules out.
 *
 * It sits beside {@see EmployeeDocumentExpiring} rather than being folded into
 * it, because the two are about different rows with different policies — a
 * passport lapsing and a working-at-heights card lapsing are two questions a
 * listener will answer differently — but they are deliberately shaped the same
 * so one listener can be registered against both without adapting.
 *
 * `expiresOn` is carried alongside the enrolment rather than read off it by a
 * future listener, because the date is the entire message: "in 90 days" and
 * "in 3" are the same event and a different urgency.
 *
 * ShouldDispatchAfterCommit so a listener reading the row sees the post-marker
 * state rather than trying to guess whether the warning had already been
 * raised while the scan's transaction was still open.
 */
class EmployeeTrainingExpiring implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly EmployeeTraining $training,
        public readonly string $expiresOn,
    ) {}
}
