# Deployment

> **Status:** Nothing is deployed yet — this document defines the architecture and
> runbook to deploy to. What is live is local: `php artisan serve` against
> `hrms_laravel`, and a Flutter debug build pointed at it with
> `--dart-define=API_BASE_URL`. Phase 4 added the environment variables in §3.1.

---

## 1. Architecture (cloud-ready, no vendor lock-in)

```
        Flutter Mobile App (Android / iOS)
                    │
                   HTTPS
                    │
        ┌───────────▼────────────┐
        │   Load balancer / TLS  │
        └───────────┬────────────┘
                    │
        ┌───────────▼────────────┐
        │   Web server (nginx)   │  static files + PHP-FPM
        └───────────┬────────────┘
                    │
        ┌───────────▼────────────┐
        │   Laravel REST API     │  PHP 8.2+ / PHP-FPM
        └─────┬──────────┬───────┘
              │          │
     ┌─────────▼─────┐  ┌───▼─────────────────┐
     │  MariaDB 10.4 │  │ Private file storage│
     │  (backups)    │  │ (encrypted at rest) │
     └───────────────┘  └─────────────────────┘

  Side processes (same host or separate):
    • Queue worker      — notifications, PDF generation, exports
    • Laravel Scheduler — document expiry, LOP conversion, reminders
    • Firebase FCM      — push delivery
    • Backup job        — nightly DB + file backups
    • Monitoring / logging
```

**Deployable to:** AWS, Azure, Google Cloud, DigitalOcean, Hostinger VPS, or any
Linux server. Only standard requirements — **no cloud-specific services required**.

---

## 2. Server Requirements

| Component | Minimum | Recommended |
|---|---|---|
| OS | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| PHP | 8.2 | 8.3 |
| Extensions | `pdo_mysql`, `openssl`, `curl`, `mbstring`, `zip`, `gd`, `intl`, `bcmath`, `fileinfo`, `exif`, `dom` | + `redis`, `opcache` |
| MariaDB | 10.4 | **10.4.28** |
| Web server | nginx or Apache + PHP-FPM | nginx |
| Memory | 2 GB | 4 GB |
| Storage | 20 GB SSD | 50 GB SSD + object storage for files |
| SSL | Let's Encrypt / any CA | Managed certificate with auto-renewal |

---

## 3. Environment Setup

### 3.1 Application environment

```dotenv
APP_NAME="HRMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hrms_laravel
DB_USERNAME=hrms_app
DB_PASSWORD=<strong, unique>

SESSION_DRIVER=database
QUEUE_CONNECTION=redis          # or database
CACHE_STORE=redis              # or database
FILESYSTEM_DISK=private        # private storage for sensitive files

SANCTUM_STATELESS=true

# --- Phase 3: authentication -------------------------------------------------
BCRYPT_ROUNDS=12                  # cost of the password hash; 4 is for tests only
LOGIN_RATE_LIMIT_MAX_ATTEMPTS=5
LOGIN_RATE_LIMIT_DECAY_MINUTES=1
PASSWORD_RESET_RATE_LIMIT_MAX_ATTEMPTS=5
PASSWORD_RESET_RATE_LIMIT_DECAY_MINUTES=15

# Password reset stays OFF until a mailer below can genuinely deliver.
# MAIL_MAILER=log writes the link into a log file nobody reads, and telling a
# user "email sent" when none was would be a lie.
PASSWORD_RESET_ENABLED=false
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=<smtp user>
MAIL_PASSWORD=<smtp password>
MAIL_ENCRYPTION=tls

# --- Phase 4: organisation modules -------------------------------------------
# Bounds on what may be written to a site's geofence radius, in metres. The
# radius itself is a column on the site row; these only decide what a form may
# submit, so a different ceiling is an env change and not a code change.
GEOFENCE_MIN_RADIUS_METRES=10
GEOFENCE_MAX_RADIUS_METRES=10000

# Role visibility narrowing (Site Supervisor / Site Engineer see only their own
# projects; Project Manager / Site Supervisor / Site Engineer see only their own
# workforce) is deliberately NOT env-driven — it is `config/hrms.php`
# => 'visibility'. A custom deployment that needs different lists edits that
# file, and both the list queries and the policies read the same two arrays, so
# narrowing a role narrows the collection and the single-record check together.

# --- Phase 5: attendance -----------------------------------------------------
# Limits on what a device may claim, not secrets. They bound a GPS fix and the
# number of check-in writes per minute; every one reads through config/hrms.php
# or config/rate_limiting.php, so this is the only place an operator changes it.
ATTENDANCE_MAX_GPS_ACCURACY_METRES=100
ATTENDANCE_VALIDATE_CHECKOUT_GEOFENCE=true
ATTENDANCE_RATE_LIMIT_MAX_ATTEMPTS=30
ATTENDANCE_RATE_LIMIT_DECAY_MINUTES=1

# --- Phase 5: selfie storage -------------------------------------------------
# Re-encoded on the way in — that re-encoding is what discards the EXIF block
# (GPS fix, camera model, software string) along with any client filename.
# MAX_PIXELS bounds width x height, not per edge, so a crafted gigapixel file
# is refused before anything tries to decode it.
HRMS_SELFIE_DIRECTORY=attendance-selfies
HRMS_SELFIE_MAX_KB=5120
HRMS_SELFIE_MAX_PIXELS=16777216
HRMS_SELFIE_JPEG_QUALITY=85

# --- Phase 6: leave certificates ---------------------------------------------
# Private storage for medical certificates. Both live on the `local` disk
# (storage/app/private) and are reachable only through
# GET /api/v1/leave/{id}/certificate behind a policy — never a public path.
# Unlike a selfie these are stored exactly as sent: a doctor's note is a
# document and must not be re-encoded.
HRMS_CERTIFICATE_DIRECTORY=leave-certificates
HRMS_CERTIFICATE_MAX_KB=5120

# --- Phase 6: scheduler mechanics ---------------------------------------------
# Minute of each hour the deadline job is dispatched, and how long a running
# pass may hold the overlap mutex. Both are deployment mechanics; see §5.
HRMS_SICK_TICK_MINUTE=17
HRMS_SICK_OVERLAP_MINUTES=60

# How long a sick leave may go without a medical document before the scheduler
# converts it to LOP. This one is deliberately NOT an env var: it is the
# `settings` row `leave.sick_certificate_deadline_days` (default 2), read on
# every use through SettingsService. There is no settings endpoint yet, so
# it is changed with a row update rather than a config:clear. A leave type
# with document_deadline_days > 0 wins over it.
```

**Critical:** `APP_DEBUG=false` in production. A debug page can leak env secrets.

**Critical:** only flip `PASSWORD_RESET_ENABLED=true` in the same commit as a
`MAIL_MAILER` that has been verified to deliver. The endpoint answers `501`
while it is `false`, and `config:cache` bakes the value in — changing it needs
a `php artisan config:clear`.

### 3.2 Post-deploy commands

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan storage:link        # only if any public disk is used
```

**When the permission catalogue grows** (it has twice — Phase 4 and Phase 6),
re-run the idempotent seeders rather than the whole `DatabaseSeeder`:

```bash
php artisan db:seed --class=PermissionSeeder --force      # creates + retires
php artisan db:seed --class=RolePermissionSeeder --force  # syncPermissions
php artisan db:seed --class=RoleSeeder --force            # only for new roles
```

All are `firstOrCreate` / `syncPermissions`, so they add what is missing and
change nothing else — no sample rows, no duplicated roles. `PermissionSeeder`
also runs `PermissionSeeder::RETIRED` (today: `leave.request`), which **deletes**
permissions nothing references any more, so an upgraded database does not keep
stale grants.

**Phase 6 also added three configuration seeders** — run them on first deploy
of this release:

```bash
php artisan db:seed --class=ApprovalWorkflowSeeder --force  # LEAVE-STD / LEAVE-FAST / OT-STD
php artisan db:seed --class=LeaveTypeSeeder --force         # AL / SL / EL / UL / OTH
php artisan db:seed --class=SettingSeeder --force           # adds leave.sick_certificate_deadline_days
```

Without them the API still starts, but `POST /leave` has no types to offer and
`submit` has no workflow to materialise.

`ApprovalWorkflowSeeder` is safe to re-run: each workflow is keyed by
`(subject_type, code)`, and it only clears `is_default` when the definition
being written asserts one — re-seeding cannot quietly strip the default away.

---

## 4. Queue Worker

The queue carries whatever a controller cannot afford to finish inside a
request — today that means `EnforceSickCertificateDeadlines`, and later PDF
report generation and notification dispatch.

**No connection argument on purpose:** `queue:work` reads `QUEUE_CONNECTION`
from `.env`, so the same unit works whether a deployment uses `database` or
`redis`. Hard-coding `queue:work redis` in a unit file while `.env` says
`database` is a silent failure — the worker starts, reports no jobs, and
nobody notices until a deadline slips.

**systemd unit** `/etc/systemd/system/hrms-worker.service`:

```ini
[Unit]
Description=HRMS Laravel Queue Worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/hrms/backend
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now hrms-worker
sudo systemctl restart hrms-worker
```

> Workers are restarted regularly (`--max-time`) to avoid memory leaks in long-running
> PHP processes.

**Supervisor alternative** `/etc/supervisor/conf.d/hrms-worker.conf` — the
common choice on Debian/Ubuntu, and what most Laravel deployments use:

```ini
[program:hrms-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/hrms/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/hrms/backend/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status hrms-worker:*
```

Whichever one is used, the requirement is the same: **an always-on process,
not a shell somebody has to remember to leave open.** A worker started by
hand dies with the terminal, and the next person to log in has no idea one
was needed.

**Phase 6 made the worker load-bearing.** `EnforceSickCertificateDeadlines`
implements `ShouldQueue`, so the scheduler only *dispatches* it — with no
worker running, the conversion never happens and sick leave quietly keeps
looking pending. On a single host set `QUEUE_CONNECTION=database` (the `jobs`
table already exists) and let the unit above consume it. A missed conversion
is recoverable: the job is idempotent and the next hourly run picks up
whatever is past its deadline, so a worker outage *delays* LOP, it does not
lose it.

---

## 5. Scheduler (cron)

Business rules that **must** run server-side — never depend on the mobile app being open:

| Job | Purpose | Default schedule | Status |
|---|---|---|---|
| `EnforceSickCertificateDeadlines` | Convert leave past its certificate deadline to **LOP**, release the balance, dispatch `LeaveConvertedToLop` | **hourly at :17** — `hourlyAt(config('hrms.scheduling.tick_minute'))`, `HRMS_SICK_TICK_MINUTE` | ✅ Phase 6 |
| Document expiry reminders | Passport / visa / Emirates ID / certificates | Daily | ⬜ Phase 9 |
| Training certificate expiry | Notify employee + HR | Daily | ⬜ Phase 9 |
| Leave reminders | Pending approvals, upcoming leave | Daily | ⬜ with notifications |
| Attendance reminders | Missing check-in / check-out | Daily | ⬜ with notifications |
| Payroll processing | Monthly run | Monthly | ⬜ Phase 10 |
| Notification dispatch | FCM delivery | Every minute | ⬜ Phase 11 |

**Cron entry** (`crontab -e` for the deploy user):

```cron
* * * * * cd /var/www/hrms/backend && php artisan schedule:run >> /dev/null 2>&1
```

The entry must run **every minute** — `schedule:run` is the dispatcher, and
Laravel's scheduler does the rest. Scheduling it daily instead would mean a
job registered as `hourlyAt(17)` (the default tick) still fires, but only when the daily run
happens to land in the right hour; on a VPS whose cron is the only clock, that
is the difference between "converted at 17:17" and "converted whenever".

Verify after deploy:

```bash
$ php artisan schedule:list
17 * * * *  App\Jobs\EnforceSickCertificateDeadlines .... Next Due: 32 seconds from now
```

Two guards are set in `routes/console.php` and are **not** visible in
`schedule:list`, so check the file rather than the output:

```php
Schedule::job(new EnforceSickCertificateDeadlines)
    ->hourlyAt((int) config('hrms.scheduling.tick_minute'))        // HRMS_SICK_TICK_MINUTE, clamped to 0-59
    ->withoutOverlapping((int) config('hrms.scheduling.overlap_minutes')) // HRMS_SICK_OVERLAP_MINUTES, default 60
    ->onOneServer();
```

`withoutOverlapping(60)` protects against a cron entry that has not finished
its previous minute; `onOneServer()` protects against two hosts sharing a
database. A third guard lives in the job itself (`$uniqueFor = 3600`), and a
*fourth* in the data (`certificate_checked_at`), so even all the locks being
defeated converts nothing twice.

The tick minute and overlap window are env-tunable because they are
deployment mechanics; the **deadline** they enforce is not, because it is a
business rule about people — `leave.sick_certificate_deadline_days` in the
`settings` table, default 2 days.

**The schedule needs a queue worker too** — see §4.

---

## 6. Nginx Configuration (reference)

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

    ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;

    root /var/www/hrms/backend/public;
    index index.php;

    client_max_body_size 20M;          # image uploads

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # never serve private storage
    location ^~ /storage/app/private { deny all; return 404; }

    # block .env and dotfiles
    location ~ /\. { deny all; }
}
```

Force HTTPS redirect and HSTS:
```nginx
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$host$request_uri;
}
```

---

## 7. File Storage & Backups

### 7.1 Storage layout

```
backend/storage/app/private/
├── attendance-selfies/{employeeId}/{uuid}.jpg   Phase 5, re-encoded by GD
├── leave-certificates/{employeeId}/{uuid}.pdf   Phase 6, kept exactly as sent
├── site-report-photos/activity/{reportId}/{uuid}.jpg   Phase 7, re-encoded
├── site-report-photos/daily/{reportId}/{uuid}.jpg      Phase 7, re-encoded
├── documents/employees/     passports, IDs, visas, contracts   ⬜ Phase 9
└── documents/payroll/       salary slips, certificates         ⬜ Phase 10
```

There is **no `reports/` directory and no stored PDF.** The daily site
report's document is rendered on demand by `GET /daily-site-reports/{id}/pdf`
and streamed, so nothing to back up, nothing to expire, and no path a
response could leak.

Never inside the web root. All three live directories are reachable only
through an authenticated route that runs a policy — `GET /attendance/{id}/selfie`,
`GET /leave/{id}/certificate` and
`GET /daily-site-reports/{id}/photos/{photo}` (plus its activity-report
twin) — and all answer `Cache-Control: no-store`. No response body ever
contains a filesystem path, so there is no URL to leak even if a JSON
payload is logged somewhere.

`selfie_directory`, `certificate_directory` and `report_photo_directory`
come from `config/hrms.php` (`HRMS_SELFIE_DIRECTORY`,
`HRMS_CERTIFICATE_DIRECTORY`, `HRMS_REPORT_PHOTO_DIRECTORY`) — change them
there, not by moving the folders afterwards.

### 7.2 Backup strategy

| What | How | Frequency | Retention |
|---|---|---|---|
| Database | `mysqldump` → compressed → offsite | **Nightly** | 7 daily, 4 weekly, 6 monthly |
| Private files | `rsync` / object-storage sync → offsite | **Nightly** | 30 days |
| `.env` + config | Manual encrypted copy on change | On change | Indefinite |
| Application code | Git repository | Every commit | Indefinite |

**Example backup script** (`scripts/backup.sh`):

```bash
#!/usr/bin/env bash
set -euo pipefail

STAMP=$(date +%F-%H%M)
DB_BACKUP="/backups/db/hrms_${STAMP}.sql.gz"
FILE_BACKUP="/backups/files/hrms-files_${STAMP}.tar.gz"

mysqldump --single-transaction --routines --triggers hrms_laravel | gzip > "$DB_BACKUP"
tar -czf "$FILE_BACKUP" -C /var/www/hrms/backend/storage/app private

# upload to offsite (S3 / rsync / whatever) …
# ./offsite-upload.sh "$DB_BACKUP" "$FILE_BACKUP"

# prune local copies older than 30 days
find /backups -name "hrms_*.sql.gz"   -mtime +30 -delete
find /backups -name "hrms-files_*.tar.gz" -mtime +30 -delete
```

Run nightly at 02:00:
```cron
0 2 * * * /var/www/hrms/scripts/backup.sh >> /var/www/hrms/scripts/backup.log 2>&1
```

### 7.3 Restore procedure

```bash
# 1. stop the queue worker (avoid writes during restore)
sudo systemctl stop hrms-worker

# 2. restore database
gunzip -c /backups/db/hrms_2026-09-26-0200.sql.gz | mysql -u hrms_app -p hrms_laravel

# 3. restore files
tar -xzf /backups/files/hrms-files_2026-09-26-0200.tar.gz \
     -C /var/www/hrms/backend/storage/app

# 4. verify
php artisan migrate:status
sudo systemctl start hrms-worker
```

**A backup you have never restored is not a backup.** Test a full restore quarterly.

---

## 8. Mobile App Release

```bash
cd mobile

# Android release build (requires signing keystore — NEVER committed)
flutter build appbundle --release      # .aab for Google Play
flutter build apk --release            # .apk for direct distribution
```

### Android signing

| Item | Location |
|---|---|
| Keystore (`*.jks`) | **Off-repo**, password manager + encrypted backup |
| `android/key.properties` | **git-ignored**, referenced by `build.gradle` |
| Upload key | Play App Signing recommended |

Keystore loss = inability to update the app. Back it up before first release.

### Firebase setup

1. Create the Firebase project
2. Add the Android app → download `google-services.json` → `mobile/android/app/`
   (**git-ignored**)
3. Server-side: Firebase Admin SDK credentials on the server only
   (**never** shipped in the APK)

---

## 9. Monitoring & Logging

| Signal | Tool |
|---|---|
| Application errors | Laravel log → aggregated (Sentry / CloudWatch / etc.) |
| Queue failures | `php artisan queue:failed` (`failed_jobs` table) + alert |
| Queue backlog | row count in the `jobs` table — a growing count with an idle worker is the LOP deadline quietly not being enforced |
| Scheduler missed runs | `schedule:run` heartbeat monitoring |
| Worker alive | `systemctl status hrms-worker` / `supervisorctl status hrms-worker:*` |
| Disk / CPU / memory | Server agent |
| Uptime | HTTP check on `/api/v1/health` |
| Slow queries | MariaDB slow log + Laravel query logging in debug |

### Logging rules

**Log:** API errors, sync failures, authentication failures, important backend failures.
**Never log:** passwords, auth tokens, private document contents.

---

## 10. Deployment Runbook

```bash
# 1. pull latest code
git fetch && git checkout <tag>

# 2. dependencies
composer install --no-dev --optimize-autoloader

# 3. config
cp .env.example .env        # first deploy only
php artisan key:generate    # first deploy only

# 4. cache
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. database
php artisan migrate --force

# 6. restart workers (systemd — use `supervisorctl restart hrms-worker:*` instead if Supervisor)
sudo systemctl restart hrms-worker

# 7. verify
php artisan about
curl -s https://api.example.com/api/v1/health
php artisan schedule:list      # the deadline job must be listed
php artisan queue:failed       # must be empty after a deploy
```

---

## 11. Local Development (Windows)

| Task | How |
|---|---|
| Start database | `scripts/start-db.ps1` (background process — no service install required without admin rights) |
| Start API | `cd backend && php artisan serve` |
| Start Flutter | `cd mobile && flutter run` |

> **Note:** toolchains (Flutter SDK, Android SDK, JDK, Gradle cache, pub cache) are
> installed on a **secondary drive**, not the system drive, to avoid filling it.
> Gradle and pub cache locations are controlled by `GRADLE_USER_HOME` and `PUB_CACHE`.

---

## 12. Status

| Item | Status |
|---|---|
| This document (target architecture + runbook) | ✅ Written |
| Phase 3 auth environment variables documented in §3.1 | ✅ |
| Phase 4 geofence + visibility config documented in §3.1 | ✅ |
| Phase 5 selfie env keys (`HRMS_SELFIE_MAX_KB`, `HRMS_SELFIE_MAX_PIXELS`, `HRMS_SELFIE_JPEG_QUALITY`) present in `.env.example` | ✅ Added in operational hardening — no secrets, defaults only |
| Phase 6 certificate env keys documented in §3.1 | ✅ |
| Phase 6 certificate env keys present in `.env.example` | ✅ Added in operational hardening |
| `.env.example` contains no passwords, keys or credentials | ✅ placeholders only; `MAIL_PASSWORD`, `AWS_SECRET_ACCESS_KEY`, `DB_PASSWORD` remain blank or commented |
| Scheduler registration documented with real `schedule:list` output (§5) | ✅ |
| Production cron line documented (§5) | ✅ `* * * * * cd … && php artisan schedule:run` |
| Queue worker identified as required by the Phase 6 deadline job (§4) | ✅ |
| Queue worker documented under **both** systemd and Supervisor (§4) | ✅ |
| Server provisioning | ⬜ |
| CI/CD pipeline | ⬜ |
| SSL certificate | ⬜ |
| Password reset enabled (needs a delivering mailer) | ⬜ |
| Backup script implemented | ⬜ |
| Restore tested | ⬜ |
