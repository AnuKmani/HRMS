<?php

namespace App\Services\Notifications\Fcm;

use App\Models\DeviceToken;
use App\Models\InAppNotification;
use Illuminate\Support\Facades\Log;

/**
 * The gateway used when Firebase credentials are absent — which is every
 * developer machine and every CI run in this project.
 *
 * **It does not pretend to deliver.** It returns normally (so the inbox
 * row, which is the durable record, is not rolled back) and writes one
 * structured line saying the push was skipped and why. The alternative —
 * returning as though FCM accepted the message — would be the exact lie
 * this phase's brief forbids: a green test asserting a delivery that
 * never happened.
 *
 * The log line is at `notice` rather than `info` because on a production
 * box where credentials *were* expected, a silently skipped push is
 * something an operator should see.
 */
class DisabledFcmGateway implements FcmGateway
{
    public function available(): bool
    {
        return false;
    }

    public function send(DeviceToken $device, InAppNotification $notification): void
    {
        Log::notice('fcm.push_skipped', [
            'reason' => 'firebase_not_configured',
            'notification_id' => $notification->id,
            'user_id' => $device->user_id,
            'platform' => $device->platform,
            // Never the token. It is a bearer credential for a handset.
        ]);
    }
}
