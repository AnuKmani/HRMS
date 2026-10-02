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

    /*
    |--------------------------------------------------------------------------
    | File uploads
    |--------------------------------------------------------------------------
    |
    | One limiter for every multipart endpoint in the application —
    | documents, training certificates, expense receipts, site photos, sick
    | certificates, selfies. The resource being protected is the same in all
    | of them (disk and bandwidth), so seven separate limits would be seven
    | numbers waiting to disagree with each other.
    |
    | 60 in five minutes is a person attaching evidence to a handful of
    | claims in one sitting. It is not a person (or a script) pushing
    | hundreds of files.
    |
    */

    'upload' => [
        'max_attempts' => (int) env('UPLOAD_RATE_LIMIT_MAX_ATTEMPTS', 60),
        'decay_minutes' => (int) env('UPLOAD_RATE_LIMIT_DECAY_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | High-risk mutations
    |--------------------------------------------------------------------------
    |
    | The write endpoints that are not already covered by a tighter limiter:
    | expense submission, training assignment, asset hand-over and return,
    | employee create/edit.
    |
    | Deliberately loose — 120 a minute is more than any person does —
    | because a rate limit that trips on a busy Monday is a bug users blame
    | on the software. Its job is to make scripted abuse *expensive*, not
    | to police anybody's working day.
    |
    */

    'write' => [
        'max_attempts' => (int) env('WRITE_RATE_LIMIT_MAX_ATTEMPTS', 120),
        'decay_minutes' => (int) env('WRITE_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Device token registration
    |--------------------------------------------------------------------------
    |
    | One call per real handset, plus a refresh now and then. Ten a minute
    | is an enormous ceiling for a legitimate client and a low one for
    | something rotating identities against the unique index.
    |
    */

    'device_token' => [
        'max_attempts' => (int) env('DEVICE_TOKEN_RATE_LIMIT_MAX_ATTEMPTS', 10),
        'decay_minutes' => (int) env('DEVICE_TOKEN_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Report exports
    |--------------------------------------------------------------------------
    |
    | Synchronous and queued exports share this. A queued export is a
    | database walk plus a file in private storage; asking for one in a loop
    | is the cheapest available way to fill a disk with reports nobody will
    | download.
    |
    */

    'export' => [
        'max_attempts' => (int) env('EXPORT_RATE_LIMIT_MAX_ATTEMPTS', 10),
        'decay_minutes' => (int) env('EXPORT_RATE_LIMIT_DECAY_MINUTES', 5),
    ],

];
