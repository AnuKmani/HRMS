<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A login HR handed out is a temporary one: until its owner picks their own
 * password, the only things it can do are read who it is, sign out, and
 * change the password. The app sends the person straight to that screen;
 * this is the server saying the same thing to anything else.
 */
class EnsurePasswordChanged
{
    private const ALLOWED = [
        'api/v1/auth/me',
        'api/v1/auth/logout',
        'api/v1/auth/change-password',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password && ! in_array($request->path(), self::ALLOWED, true)) {
            return ApiResponse::error(
                'Please set your own password before continuing.',
                ['password' => 'password_change_required'],
                403,
            );
        }

        return $next($request);
    }
}
