<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Row-level visibility
    |--------------------------------------------------------------------------
    |
    | A `*.view` permission answers one question: may this role open the
    | module at all? It says nothing about *which rows*. The roles listed
    | below are given a narrower answer — they only ever see the rows
    | attached to what they personally run.
    |
    | Everything not listed reads every row its permission already admits.
    | That direction is deliberate: an unlisted custom role falls back to
    | the plain meaning of the permission instead of silently seeing
    | nothing, which would be a confusing failure with no obvious cause.
    |
    | Policies and the list queries both read these two lists, so narrowing
    | a role here narrows the collection *and* the single-record check at
    | the same time. A policy that checked only the individual record while
    | the index returned everything would be no boundary at all.
    |
    */

    'visibility' => [

        /*
        | Only projects/sites they personally manage or supervise.
        |
        | Project Manager is deliberately absent: it holds both
        | `projects.manage` and `sites.manage`, i.e. it owns those modules
        | and can see all of them. Its narrowing is on employees instead.
        */
        'project' => ['Site Supervisor', 'Site Engineer'],

        /*
        | Only employees attached to the projects/sites they run — plus
        | their own record, which is never gated by scope.
        |
        | Project Manager is the reason this list exists: they may open
        | every project but only the workforce behind the ones they manage,
        | and never a colleague's salary.
        */
        'employee' => ['Project Manager', 'Site Supervisor', 'Site Engineer'],

        /*
        | Attendance follows the workforce rather than the site, for the same
        | reason employee visibility does: a supervisor's interest is the
        | people on their ground, not the calendar.
        |
        | Unlike the two lists above, this one FAILS CLOSED. `attendance`
        | rows carry where somebody was and when, so a role the operator has
        | not listed reads only its own records rather than everything — see
        | Visibility::mayViewOthersAttendance().
        */
        'attendance' => ['Project Manager', 'Site Supervisor', 'Site Engineer'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Geofence bounds
    |--------------------------------------------------------------------------
    |
    | Validation limits for a site's radius, in metres. The radius itself
    | lives on the site row and is read from there every time a check-in is
    | judged — these bounds only decide what may be written in the first
    | place, so tightening them is a config change rather than a code change
    | when a deployment needs a different ceiling.
    |
    | Nothing anywhere hard-codes a site's coordinates: they are columns.
    |
    */

    'geofence' => [
        'min_radius_metres' => (float) env('GEOFENCE_MIN_RADIUS_METRES', 10),
        'max_radius_metres' => (float) env('GEOFENCE_MAX_RADIUS_METRES', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attendance
    |--------------------------------------------------------------------------
    |
    | Phase 5's check-in rules. Two of these are ceilings on what a *device*
    | may claim, and one is a switch a deployment may need to flip.
    |
    | Nothing here says when a working day starts or ends. That is the shift
    | row's answer, falling back to the seeded `working_hours.default` and
    | `attendance.grace_period_minutes` settings — see ScheduleResolver.
    |
    */

    'attendance' => [

        /*
        | Worst GPS accuracy worth acting on, in metres.
        |
        | A fix reported as "accurate to 400 m" cannot tell a site from the
        | next block over, so accepting it would be theatre: the geofence
        | would pass or fail on noise. Anything above this is rejected with a
        | message the user can act on ("move into open sky and try again")
        | rather than a bare 422.
        */
        'max_gps_accuracy_metres' => (float) env('ATTENDANCE_MAX_GPS_ACCURACY_METRES', 100),

        /*
        | Must check-out be inside the same geofence as check-in?
        |
        | On by default: leaving from where you entered is the rule, and it
        | is what makes the stored check-out coordinates mean anything. A
        | deployment whose workforce clocks out from a moving vehicle can set
        | this false rather than hand-editing the code.
        |
        | The rate limit for these writes lives in config/rate_limiting.php
        | beside the other limiters, so there is one file to read when
        | tuning how often anything may happen.
        */
        'validate_checkout_geofence' => (bool) env('ATTENDANCE_VALIDATE_CHECKOUT_GEOFENCE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Private file storage
    |--------------------------------------------------------------------------
    |
    | Attendance selfies are evidence about a person, so they live on a disk
    | that has no public URL and no route that lists it. The only way to read
    | one is `GET /api/v1/attendance/{id}/selfie`, which runs the same policy
    | as the attendance row itself.
    |
    | What is written there is never the upload: SelfieSanitizer decodes the
    | incoming file and re-encodes it as JPEG, which is what drops the EXIF
    | block — GPS fix, camera model, software string — along with any
    | filename the client may have supplied.
    |
    | The path below is relative to the storage root and is never sent to a
    | client — AttendanceResource exposes `has_selfie`, not `*_path`.
    |
    */

    'storage' => [
        'selfie_directory' => (string) env('HRMS_SELFIE_DIRECTORY', 'attendance-selfies'),

        // What a selfie may weigh on the way in, checked against the part's
        // own size by the request and again by SelfieStore.
        'selfie_max_kilobytes' => (int) env('HRMS_SELFIE_MAX_KB', 5120),

        // Width x height, not "per edge": a decoded image costs roughly
        // four bytes a pixel, so this is what bounds the buffer a single
        // crafted request may ask for. 16 777 216 is 4096 x 4096 — far past
        // any selfie, and the reason a gigapixel file is refused instead of
        // being decoded.
        'selfie_max_pixels' => (int) env('HRMS_SELFIE_MAX_PIXELS', 16_777_216),

        // What SelfieSanitizer emits. JPEG because it is the format every
        // client already sends, it has no alpha channel to carry anything
        // through, and re-encoding into it is precisely what discards EXIF.
        // 85 is visually lossless for a face and about a tenth the size of
        // 100 on a phone-camera frame.
        'selfie_jpeg_quality' => (int) env('HRMS_SELFIE_JPEG_QUALITY', 85),

        /*
        | Medical certificates for sick leave.
        |
        | Same rules as selfies — private disk, no public URL, server-minted
        | filename, read back only through the leave policy — but a different
        | answer to "what may arrive": a doctor's note is a document, so PDF
        | and image types are all acceptable and nothing is re-encoded. What
        | IS stripped is the client's filename, which never reaches the disk.
        |
        | Certificates are deliberately NOT merged into employee document
        | management: that module does not exist yet, and bolting a medical
        | file onto it now would mean designing a document store to hold one
        | upload. See docs/SECURITY.md.
        */
        'certificate_directory' => (string) env('HRMS_CERTIFICATE_DIRECTORY', 'leave-certificates'),

        'certificate_max_kilobytes' => (int) env('HRMS_CERTIFICATE_MAX_KB', 5120),

        /*
        | Photographs on site reports (Phase 7).
        |
        | Identical in kind to a selfie — private disk, no public URL,
        | server-minted filename, re-encoded by SelfieSanitizer so EXIF and
        | GPS are discarded — and served only through the report's own
        | policy-checked photo route with `no-store`.
        |
        | A directory and a size ceiling of its own rather than reusing the
        | selfie ones: they are separate rows in an operator's mental model
        | (an attendance photo is about a person, a report photo is about a
        | building), and being able to retune one without touching the other
        | is worth two lines of config. The decode budget and the JPEG
        | quality are NOT repeated here — those are properties of
        | SelfieSanitizer, which reads `selfie_max_pixels` and
        | `selfie_jpeg_quality` for every image it processes, reports
        | included.
        */
        'report_photo_directory' => (string) env('HRMS_REPORT_PHOTO_DIRECTORY', 'site-report-photos'),

        'report_photo_max_kilobytes' => (int) env('HRMS_REPORT_PHOTO_MAX_KB', 5120),

        /*
        | Receipts on expense claims (Phase 9).
        |
        | The same rules as a medical certificate — private disk, no
        | public URL, server-minted filename, read back only through the
        | claim's own policy — because a receipt is a document in exactly
        | the same sense: somebody's invoice or card slip, filed against a
        | named person's claim for money. Nothing is re-encoded either. A
        | PDF has to stay a PDF or it stops being a document anybody can
        | open, and stripping image metadata would mean parsing formats
        | this app has no library for. What IS dropped is the client's
        | filename, which is the one piece of metadata the app itself
        | creates and the one that carries path-like rubbish.
        |
        | A directory and a ceiling of their own rather than reusing the
        | certificate's: an operator who raises the scan limit for medical
        | notes should not silently raise the receipt limit too.
        */
        'expense_receipt_directory' => (string) env('HRMS_EXPENSE_RECEIPT_DIRECTORY', 'expense-receipts'),

        'expense_receipt_max_kilobytes' => (int) env('HRMS_EXPENSE_RECEIPT_MAX_KB', 5120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled enforcement
    |--------------------------------------------------------------------------
    |
    | Exactly one thing in this application is scheduled: the hourly
    | conversion of overdue, certificate-less sick leave into Loss of Pay.
    | These two values tune *when and how often* that runs — both are
    | deployment mechanics, which is why they are here rather than in a
    | route file.
    |
    | What is deliberately NOT here: the deadline itself. Two days is a
    | business rule about people, so it lives in the `settings` table as
    | `leave.sick_certificate_deadline_days` and is changed by whoever runs
    | the company, not by whoever deploys the code. Likewise `tries` and
    | `uniqueFor` on the job — see EnforceSickCertificateDeadlines for why
    | each is a class-level decision rather than a knob.
    |
    */

    'scheduling' => [

        /*
        | Minute of the hour to run, 0-59. Clamped rather than validated so
        | a typo in .env cannot make the scheduler throw at load time and
        | silently stop every scheduled task; it lands on a sane minute
        | instead of taking the rest of the application down with it.
        */
        'tick_minute' => max(0, min(59, (int) env('HRMS_SICK_TICK_MINUTE', 17))),

        // How long a running pass must take before the overlap mutex gives
        // up and lets another one start. Longer than any plausible pass —
        // the job selects a bounded set of rows and each is its own
        // transaction — because a mutex that expires mid-run is worse than
        // no mutex: it converts one run into two.
        'overlap_minutes' => max(1, (int) env('HRMS_SICK_OVERLAP_MINUTES', 60)),
    ],

];
