<?php

namespace App\Services\Notifications\Fcm;

use App\Models\DeviceToken;
use App\Models\InAppNotification;

/**
 * Where a push actually goes.
 *
 * It exists so the rest of the system never has to know whether Firebase
 * is configured in this environment. `NotificationService` queues a job;
 * the job asks the container for a `FcmGateway`; the container decides
 * between `FirebaseFcmGateway` (credentials present) and
 * `DisabledFcmGateway` (they are not). Nothing else branches on it — and,
 * importantly, nothing *pretends*: the disabled gateway skips and says so
 * in the log rather than answering as though the handset received it.
 *
 * Tests bind a fake to this interface and assert what would have been
 * sent without a single network call.
 */
interface FcmGateway
{
    /**
     * Whether this gateway can actually deliver — i.e. credentials are
     * present and parseable in this environment.
     */
    public function available(): bool;

    /**
     * Send one notification to one device.
     *
     * @throws StaleTokenException when Firebase says the token no longer
     *                             exists (the job then deactivates it)
     * @throws DeliveryException for every other failure, so the job can
     *                           retry rather than losing the message
     */
    public function send(DeviceToken $device, InAppNotification $notification): void;
}
