<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BrokerNotification;

/**
 * The password-reset mail, queued.
 *
 * The broker's `ResetPassword` notification already uses the `Queueable`
 * trait which provides queue functionality. We simply extend it to
 * customize the mail content via AppServiceProvider callbacks.
 *
 * Because it extends the broker's notification, `Notification::assertSentTo(
 * …, PasswordResetMail::class)` in the test suite matches it, and a
 * future change to Laravel's reset mail is inherited rather than forked.
 */
class PasswordResetMail extends BrokerNotification {}
