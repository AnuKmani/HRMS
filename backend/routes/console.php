<?php

use App\Jobs\EnforceSickCertificateDeadlines;
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
| Phase 6 has exactly one scheduled job, and it exists because a medical
| certificate's deadline must be enforced whether or not anybody opens the
| app. A phone that is switched off, offline or uninstalled does not get to
| decide whether an absence is paid — the server's clock does.
|
| `hourlyAt(17)` rather than `daily()`: a deadline that expires at 02:00 and
| is not looked at until the next 02:00 is a day and a half late for
| everybody in a later timezone, and sick leave is the one place where
| "checked once a day" is visibly wrong to the person affected.
|
| This only runs if something invokes `php artisan schedule:run` every
| minute — a cron line in production, or the dev loop below. See
| docs/DEPLOYMENT.md: the registration is verified by `php artisan
| schedule:list`, but nothing in this repository can guarantee the crontab
| that calls it exists.
|
| The job itself is idempotent (see EnforceSickCertificateDeadlines), so a
| schedule that fires twice — or a cron that fires twice because two entries
| were added — converts nothing a second time.
|
*/
Schedule::job(new EnforceSickCertificateDeadlines)
    ->hourlyAt(17)
    ->withoutOverlapping(60)
    ->onOneServer();
