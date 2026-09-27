<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Login throttling
    |--------------------------------------------------------------------------
    |
    | Applied to POST /api/v1/auth/login through the named "login" rate limiter
    | registered in AppServiceProvider. The counter is keyed by client IP
    | alone — see the note there for why email is deliberately not part of
    | the key — so brute-forcing from one machine is throttled without giving
    | an attacker a way to lock a victim out of their own account.
    |
    | Tune here (or in .env) — never inline these numbers at the route.
    |
    */

    'login' => [
        'max_attempts' => (int) env('LOGIN_RATE_LIMIT_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('LOGIN_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset request throttling
    |--------------------------------------------------------------------------
    |
    | Covers both forgot-password and reset-password. A slower window than
    | login because the cost of each attempt is an email, not a hash compare.
    |
    */

    'password_reset' => [
        'max_attempts' => (int) env('PASSWORD_RESET_RATE_LIMIT_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('PASSWORD_RESET_RATE_LIMIT_DECAY_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance write throttling
    |--------------------------------------------------------------------------
    |
    | Covers the four POSTs that record a fact about somebody's day:
    | check-in, check-out, start visit, end visit. Generous compared with
    | login — an honest worker may tap retry a few times on bad signal, and
    | refusing a legitimate clock-in is a much worse failure than letting a
    | burst through.
    |
    | Keyed by the authenticated user rather than by IP: crews share one
    | address on site, and one device looping on a weak connection would
    | otherwise throttle the whole crew out of clocking in.
    |
    */

    'attendance' => [
        'max_attempts' => (int) env('ATTENDANCE_RATE_LIMIT_MAX_ATTEMPTS', 30),
        'decay_minutes' => (int) env('ATTENDANCE_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

];
