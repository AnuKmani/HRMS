<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use App\Models\InAppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The email copy of one in-app notification. Queued, so a slow or missing
 * mail server never delays the request that caused the notification, and
 * a failure is logged rather than retried forever: the in-app row and the
 * push are the record, the email is a courtesy.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $notification = InAppNotification::query()->with('user')->find($this->notificationId);
        $email = $notification?->user?->email;

        if ($notification === null || $email === null || $email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new NotificationMail($notification));
        } catch (Throwable $exception) {
            Log::warning('notification.email_failed', [
                'notification_id' => $notification->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
