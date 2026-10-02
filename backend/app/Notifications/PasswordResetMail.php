<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BrokerNotification;

/**
 * The password-reset mail, queued.
 *
 * Two lines of code doing one job: extending the broker's notification
 * which already implements ShouldQueue. `Password::broker()` calls
 * `$user->sendPasswordResetNotification()` synchronously inside the
 * request, which means the SMTP round trip — a handshake, an auth, a
 * message body and an answer from a server in somebody else's country —
 * is what the user is holding their phone waiting for. On a good day that
 * is 400ms; on the day the provider is having, it is the request timeout,
 * and the user is shown an error for an email that may well have sent.
 *
 * On the queue the response is immediate and honest (the message *has*
 * been accepted for delivery), and the worker takes the flakiness where
 * flakiness belongs.
 *
 * Everything else — the token, the URL, the copy, the single-use and
 * expiry rules — is the broker's `ResetPassword`, unchanged and
 * deliberately not reimplemented. See AppServiceProvider for the two
 * static callbacks that give it this application's URL and this
 * application's words.
 *
 * Because it extends the broker's notification, `Notification::assertSentTo(
 * …, ResetPassword::class)` in the test suite still matches it, and a
 * future change to Laravel's reset mail is inherited rather than forked.
 */
class PasswordResetMail extends BrokerNotification {}
