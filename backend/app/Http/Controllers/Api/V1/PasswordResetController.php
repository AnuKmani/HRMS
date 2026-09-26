<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

/**
 * POST /auth/forgot-password, POST /auth/reset-password
 *
 * BUILT BUT DELIBERATELY OFF BY DEFAULT.
 *
 * The endpoints, validation and broker wiring are complete. What is missing is
 * real mail delivery — this environment has MAIL_MAILER=log, which files the
 * message into storage/logs instead of sending it. Answering "check your inbox"
 * when nothing was sent would be a lie the user cannot act on, so until
 * PASSWORD_RESET_ENABLED=true (with a real mailer configured) both routes
 * answer 501 with an honest message.
 *
 * Nothing here fakes a sent email. See docs/API_DOCUMENTATION.md.
 *
 * Once enabled:
 *  - forgot-password always answers 202 for known AND unknown addresses, so
 *    the endpoint cannot be used to test whether an account exists;
 *  - reset-password revokes every existing token, because the premise of a
 *    reset is that the old password may be compromised.
 */
class PasswordResetController extends Controller
{
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        if (! config('auth.password_reset.enabled')) {
            return self::notEnabled();
        }

        // Broker status is intentionally discarded: RESET_LINK_SENT and
        // INVALID_USER produce byte-identical responses.
        Password::broker()->sendResetLink($request->safe()->only('email'));

        return ApiResponse::success(
            'If that email address is registered, a password reset link has been sent.',
            null,
            202
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        if (! config('auth.password_reset.enabled')) {
            return self::notEnabled();
        }

        $status = Password::broker()->reset(
            $request->safe()->only(['email', 'password', 'token']),
            function ($user, $password) {
                // PasswordBroker::reset() only hands us the new password —
                // storing it is explicitly the caller's job ("this gives the
                // user an opportunity to store the password in their
                // persistent storage"). The User model's `hashed` cast does
                // the bcrypt, so the plain text never reaches the database.
                $user->password = $password;
                $user->save();

                // The premise of a reset is that the old password may be
                // compromised, so every session that trusted it has to go.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(
                'This password reset link is invalid or has expired.',
                ['token' => 'This reset link is invalid or has expired.'],
                422
            );
        }

        return ApiResponse::success('Password reset. Sign in with your new password.');
    }

    private function notEnabled(): JsonResponse
    {
        return ApiResponse::error(
            'Password reset by email is not available yet. Your administrator can reset the account for you.',
            null,
            501
        );
    }
}
