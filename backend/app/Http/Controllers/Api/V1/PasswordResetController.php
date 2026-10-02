<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * POST /auth/forgot-password, POST /auth/reset-password
 *
 * Both are live. Phase 12 removed the `PASSWORD_RESET_ENABLED` gate and the
 * 501 that came with it, because the gate answered the wrong question: it
 * asked whether the deployment *believed* mail worked, and the answer to
 * whether mail works is a transport, not a flag.
 *
 * **What happens now, honestly:**
 *
 *  - `MAIL_MAILER=smtp` (or ses/sendgrid/…) — the message is handed to that
 *    transport. This is the production configuration and the only one in
 *    which "check your inbox" is a statement about somebody's inbox.
 *
 *  - `MAIL_MAILER=log` — Laravel's log transport writes the *entire rendered
 *    message, link included*, into `storage/logs/laravel.log`. That is a
 *    real delivery to a real place, not a fabrication: the file can be read,
 *    the link can be copied out, and the flow can be driven end to end on a
 *    laptop with no mail server. It is also a place nobody's phone shows a
 *    notification for, which is exactly why it is the development default
 *    and why production SMTP is documented rather than assumed.
 *
 * Neither case is "faked". The one thing this controller will never do is
 * return success without handing the message to the configured transport:
 * a 202 here is always followed by `Password::broker()->sendResetLink()`
 * having actually run.
 *
 * Enumeration protection is unchanged and is the reason both branches of
 * forgot-password answer with the same bytes:
 *
 *  - 202 for a known address and 202 for an unknown one;
 *  - 422 only when the *shape* of the request is wrong (an address that is
 *    not an address), which tells an attacker nothing they could not learn
 *    from a regex;
 *  - a bad or expired token on reset is a 422 naming `token`, because by
 *    then the caller is proving they already hold the account's email —
 *    at that point the useful answer is "that link has expired", not a
 *    shrug.
 *
 * On a successful reset every existing token is revoked: the premise of a
 * reset is that the old password may be compromised, so a token issued
 * before it cannot survive it.
 *
 * See docs/API_DOCUMENTATION.md and docs/SECURITY.md for the production
 * mail configuration.
 */
class PasswordResetController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password
     *
     * Rate-limited by `throttle:password_reset`, keyed per user so one
     * person cannot spend somebody else's budget — but the counter is
     * deliberately *not* part of the response, so a throttled caller
     * learns only that they are asking too often, never that a specific
     * address exists.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        // Broker status is intentionally discarded: RESET_LINK_SENT and
        // INVALID_USER produce byte-identical responses. The log below is
        // where the difference is recorded, and a log line is read by
        // operators rather than probed by scripts.
        $status = Password::broker()->sendResetLink($request->safe()->only('email'));

        Log::info('auth.password_reset_requested', [
            // Only which *outcome* — never the address itself. An
            // auth log that lists every address anybody has ever typed
            // into a form is a mailing list, and a mailing list on disk
            // is a target.
            'found' => $status !== Password::INVALID_USER,
            'throttled' => $status === Password::RESET_THROTTLED,
            'ip' => $request->ip(),
        ]);

        return ApiResponse::success(
            'If that email address is registered, a password reset link has been sent.',
            null,
            202,
        );
    }

    /**
     * POST /api/v1/auth/reset-password
     *
     * The broker owns token validity, expiry and single-use semantics; this
     * controller owns nothing but the fact that a successful swap revokes
     * every other session. Re-implementing any part of the token check here
     * would be a second place to get it subtly wrong for no gain.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        // Captured out of the closure because the success log is written
        // *after* the broker returns, and the closure is the only place
        // that has been handed the user. Nothing here is used except the id.
        $user = null;

        $status = Password::broker()->reset(
            $request->safe()->only(['token', 'email', 'password', 'password_confirmation']),
            function ($resetUser, $password) use (&$user): void {
                $user = $resetUser;

                // A one-time password, not a copy of the old one: the user
                // chose this string, and the only thing it must do is stop
                // being the string that was compromised.
                $resetUser->forceFill(['password' => Hash::make($password)])->save();

                // The premise of a reset is that the old password may be
                // compromised, so every credential issued before it dies
                // with it — including the one on the device that is
                // reading this sentence.
                $resetUser->tokens()->delete();
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            Log::info('auth.password_reset_completed', [
                'user_id' => $user?->id,
                'ip' => $request->ip(),
            ]);

            return ApiResponse::success('Password updated. All other sessions have been signed out.');
        }

        Log::info('auth.password_reset_failed', [
            'status' => $status,
            'ip' => $request->ip(),
        ]);

        // Every non-success maps to a `token` field: an expired token, a
        // used token and an unknown address are all "the link did not
        // work", and distinguishing them would tell the caller which part
        // of a stolen link to fix.
        throw ValidationException::withMessages([
            'token' => 'This reset link is invalid or has expired. Request a new one.',
        ]);
    }
}
