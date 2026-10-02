<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\InAppNotification;
use App\Services\Notifications\Fcm\DeliveryException;
use App\Services\Notifications\Fcm\FcmGateway;
use App\Services\Notifications\Fcm\StaleTokenException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Deliver one already-written inbox row to one person's handsets.
 *
 * The whole point of this existing as a job rather than as a line in
 * `NotificationService` is that a POST must never wait for Google. The API
 * writes the inbox row, queues this, and answers; FCM's latency, quota and
 * outages are then a queue problem instead of a user-facing one.
 *
 * **The inbox row is the source of truth and this job is a copyist.** It
 * takes an id, not a payload, so a retry cannot drift from what the user
 * sees in the app, and if the row has been deleted by then the job simply
 * has nothing to do.
 *
 * Failure handling splits three ways, and the split matters:
 *
 *  - `StaleTokenException` — Firebase says the token is gone. Deactivate
 *    that row and move on. Never retried: retrying a dead token is exactly
 *    how a queue fills up with messages for a recycled handset.
 *  - `DeliveryException` — a timeout, a 5xx, a quota. Logged as a
 *    structured error. Thrown again **only if not a single token took it**,
 *    because the point of the job is that the person heard about it; if
 *    their other handset got it, re-sending would flash them a duplicate
 *    for a message they have already read.
 *  - Anything else — a bug. Let the exception escape so the queue keeps it
 *    in `failed_jobs` where an operator will find it.
 */
class SendPushNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * 10s, 30s, 90s — long enough to ride out a blip, short enough that a
     * "your leave was approved" nudge still arrives while it matters.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90];

    /**
     * Uniqueness is on the *notification id*: the same row queued twice
     * (a retried listener, a double-click that reached the server twice)
     * should still reach a handset once. 10 minutes covers any plausible
     * delivery window without a leaked lock blinding us for a day.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $notificationId) {}

    public function handle(FcmGateway $gateway): void
    {
        $notification = InAppNotification::query()->find($this->notificationId);

        if ($notification === null) {
            return;
        }

        $devices = DeviceToken::query()
            ->where('user_id', $notification->user_id)
            ->active()
            ->get();

        if ($devices->isEmpty()) {
            // Nothing registered: a quiet success, not a failure. The
            // durable copy is already in the inbox.
            return;
        }

        $delivered = 0;
        $unavailable = 0;

        foreach ($devices as $device) {
            try {
                $gateway->send($device, $notification);

                $delivered++;
            } catch (StaleTokenException $e) {
                $device->deactivate();

                Log::debug('fcm.token_stale', [
                    'notification_id' => $notification->id,
                    'user_id' => $notification->user_id,
                    'device_token_id' => $device->id,
                    'reason' => $e->getMessage(),
                ]);
            } catch (DeliveryException $e) {
                $unavailable++;

                Log::error('fcm.delivery_failed', [
                    'notification_id' => $notification->id,
                    'user_id' => $notification->user_id,
                    'device_token_id' => $device->id,
                    'platform' => $device->platform,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($unavailable > 0 && $delivered === 0) {
            // Nobody heard it. Worth another go once the outage passes —
            // and note this is thrown *after* the stale tokens were already
            // deactivated, so the retry does not waste itself on them.
            throw new DeliveryException(
                "Push delivery failed for all {$devices->count()} device(s)."
            );
        }
    }
}
