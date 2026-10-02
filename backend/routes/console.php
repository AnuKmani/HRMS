<?php

use App\Jobs\EnforceSickCertificateDeadlines;
use App\Jobs\ScanDocumentExpiries;
use App\Jobs\ScanTrainingExpiries;
use App\Jobs\SendAttendanceReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Three jobs, each for the same reason said again. A medical certificate's
| deadline must be enforced whether or not anybody opens the app, and an
| employment file must lapse when its date says it does — a phone that is
| switched off, offline or uninstalled does not get to decide whether an
| absence is paid or whether a passport has expired. The server's clock
| does both.
|
| `hourlyAt(tick_minute)` rather than `daily()` for the sick leave job —
| default 17, from `config('hrms.scheduling.tick_minute')` — because a
| deadline that expires at 02:00 and is not looked at until the next 02:00
| is a day and a half late for everybody in a later timezone, and sick
| leave is the one place where "checked once a day" is visibly wrong to the
| person affected. The document scan is daily instead: no document expires
| at a time of day, and an expiry warning an hour late costs nothing.
|
| This only runs if something invokes `php artisan schedule:run` every
| minute — a cron line in production, or the dev loop below. See
| docs/DEPLOYMENT.md: the registration is verified by `php artisan
| schedule:list`, but nothing in this repository can guarantee the crontab
| that calls it exists.
|
| Both jobs are idempotent (see EnforceSickCertificateDeadlines and
| ScanDocumentExpiries), so a schedule that fires twice — or a cron that
| fires twice because two entries were added — converts nothing a second
| time.
|
| The minutes, the overlap window and the scan's hour are config/hrms.php:
| they are deployment mechanics, and a deployment that wants the tick off
| the hour changes .env rather than this file. The deadlines and warning
| windows they act on are database settings.
|
*/
Schedule::job(new EnforceSickCertificateDeadlines)
    ->hourlyAt((int) config('hrms.scheduling.tick_minute'))
    ->withoutOverlapping((int) config('hrms.scheduling.overlap_minutes'))
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Document expiry scan
|--------------------------------------------------------------------------
|
| Daily at 06:15 by default — `config('hrms.expiry.scan_hour' /
| 'scan_minute')` — chosen so "what lapsed overnight" is on the HR desk
| before the desk opens rather than arriving over the first coffee.
|
| `withoutOverlapping` and `onOneServer()` for the reason they are on the
| job above: two crons or two queue workers must not both be rewriting the
| same rows. Both need a shared cache driver, which is the one deployment
| condition this scheduler has — see docs/DEPLOYMENT.md. ShouldBeUnique on
| the job itself is the third line of defence, so the schedule is belt,
| braces and buckles.
|
| Nothing here sends anything directly. The scan raises events; the
| listeners in App\Providers\NotificationServiceProvider turn them into
| inbox rows and queued pushes.
|
*/
Schedule::job(new ScanDocumentExpiries)
    ->dailyAt(sprintf(
        '%02d:%02d',
        (int) config('hrms.expiry.scan_hour'),
        (int) config('hrms.expiry.scan_minute'),
    ))
    ->withoutOverlapping((int) config('hrms.scheduling.overlap_minutes'))
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Training certificate expiry scan
|--------------------------------------------------------------------------
|
| The same scan, for the other kind of expiring paper: a safety card that
| has lapsed stops being a card whether or not anybody looked at the
| training screen. Default 06:20 — five minutes behind the document scan —
| so two full-table reads are not competing in the same second. It reads
| `employee_trainings` only, so the order between the two is courtesy
| rather than a dependency.
|
| Same three defences as above (idempotent queries, a per-row lock, and
| ShouldBeUnique on the job), same shared-cache condition on
| `withoutOverlapping()` / `onOneServer()`, and no delivery of its own:
| the events it raises are picked up by the notification listeners.
|
*/
Schedule::job(new ScanTrainingExpiries)
    ->dailyAt(sprintf(
        '%02d:%02d',
        (int) config('hrms.expiry.training_scan_hour'),
        (int) config('hrms.expiry.training_scan_minute'),
    ))
    ->withoutOverlapping((int) config('hrms.scheduling.overlap_minutes'))
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Attendance check-in reminders
|--------------------------------------------------------------------------
|
| Registered **only when `HRMS_ATTENDANCE_REMINDER_ENABLED=true`**, which
| is why `schedule:list` shows three jobs on a default install and four on
| one that has switched this on. The registration is conditional rather
| than the job being a no-op: a schedule entry that always runs and always
| returns 0 is a line in `schedule:list` lying about what the server does.
|
| Every fifteen minutes rather than daily, because the window a reminder
| is useful in is `shift start - offset` to `shift start + grace` — a few
| dozen minutes that a single daily run would land either side of for most
| shift times. See App\Jobs\SendAttendanceReminders for every condition
| that decides who is told, and for why the job is off by default.
|
| Same `withoutOverlapping` / `onOneServer()` pair as everything above,
| and therefore the same shared-cache requirement in production.
|
*/
if ((bool) config('hrms.notifications.attendance_reminder_enabled', false)) {
    Schedule::job(new SendAttendanceReminders)
        ->everyFifteenMinutes()
        ->withoutOverlapping((int) config('hrms.scheduling.overlap_minutes'))
        ->onOneServer();
}
