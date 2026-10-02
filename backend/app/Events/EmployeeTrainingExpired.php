<?php

namespace App\Events;

use App\Models\EmployeeTraining;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A training certificate's expiry date has passed.
 *
 * Raised by the scheduled scan in the same transaction that flips the
 * enrolment from `completed` to `expired` — so the event and the status
 * change are one fact, and a listener that reads the row back sees what the
 * event describes rather than yesterday's status.
 *
 * Like {@see EmployeeTrainingExpiring} this is a hook, not a notification.
 * Nothing in Phase 11 listens to it; Phase 12 will, without touching
 * TrainingExpiryService. No channel, no recipient list, no FCM — a queued
 * push that the application cannot actually deliver is worse than silence,
 * because it reads as "sent".
 *
 * Raised whether the certificate was ever in its warning window or not: one
 * issued with an already-past expiry, or one whose date moved past while the
 * scheduler was down, still lapsed, and the scan reports the fact exactly
 * once either way.
 *
 * Only a `completed` row ever becomes `expired`, and only because the
 * *certificate* lapsed — the training itself still happened and stays on the
 * record with its completion date. That distinction is why `expired` is a
 * status and not a deletion: an expired card does not un-take the course.
 */
class EmployeeTrainingExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EmployeeTraining $training) {}
}
