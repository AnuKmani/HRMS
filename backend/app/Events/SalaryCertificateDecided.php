<?php

namespace App\Events;

use App\Models\SalaryCertificateRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A salary certificate request reached a terminal answer.
 *
 * Separate from {@see LeaveDecided} and its siblings because a salary
 * certificate is a *document being issued*, not a request being granted:
 * the person waiting is the employee, the message is "your certificate is
 * ready" or "your certificate was declined", and the reason matters in a
 * way it does not for a day's leave.
 *
 * Carries the actor because a certificate declined by Finance and one
 * declined by HR are not the same sentence to read.
 */
class SalaryCertificateDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly SalaryCertificateRequest $request,
        public readonly string $decision,
        public readonly ?User $actor = null,
    ) {}
}
