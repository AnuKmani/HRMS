# Deployment

> **Status:** Nothing is deployed yet — this document defines the architecture and
> runbook to deploy to. What is live is local: `php artisan serve` against
> `hrms_laravel`, and a Flutter debug build pointed at it with
> `--dart-define=API_BASE_URL`. Phase 4 added the environment variables in §3.1,
> Phase 6 the certificate and scheduler ones, and Phase 8 added three
> **`settings` rows** (LOP divisor and the overtime multiplier) that are
> deliberately *not* env vars — see §3.1.

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

# --- Phase 8: payroll ----------------------------------------------------------
# Deliberately NOT env vars either, and for the same reason — they are
# operational figures an operator changes between runs, not secrets or
# deployment mechanics, and config:cache would bake them into a file that has
# to be rebuilt on every change. They are `settings` rows read through
# SettingsService on every calculation:
#
#   payroll.lop_divisor_mode         fixed (the only mode today; `working_days`
#                                    is reserved so a month can be priced from
#                                    its own calendar instead)
#   payroll.lop_divisor              30     one LOP day = salary / 30
#   payroll.overtime_rate_multiplier 1.5    hourly rate x 1.5 per overtime hour
#
# The divisor does double duty: it is also how the *hourly* rate for overtime
# is derived, so changing one number changes both, which is why the three
# settings are read together inside a single run rather than per employee.
#
# BEFORE PRODUCTION: the overtime multiplier is a generic engine setting,
# not a validated statutory rate. The UAE Labour Law (and any other
# jurisdiction you deploy into) has its own rules for basic vs. basic+allowance
# and for the 1.25/1.5/2x tiers — validate and set them yourself. Nothing here
# is statutory compliance, and the schema will not stop you setting it wrong.

# --- Phase 10: employee documents -------------------------------------------
# Employee documents are identity papers, so the storage settings are env vars
# (deployment mechanics) while the *warning* window is not: which type warns
# early and by how many days is a property of the row in document_types, and
# HRMS_DOCUMENT_WARNING_DAYS below is only the fallback when a type says none.

# Private directory under storage/app/private that holds the employee file.
# The name is minted server-side as {employeeId}/{uuid}.{ext} — nothing the
# client sends becomes part of a path, so this is the only knob.
HRMS_DOCUMENT_DIRECTORY=employee-documents

# Ceiling for one document upload, in KB (10 MB by default). A passport scan
# that arrives larger is refused before it is written, not after.
HRMS_DOCUMENT_MAX_KB=10240

# Fallback warning window in days for a document type whose own
# expiry_warning_days is NULL. NULL means "use this", never "never warn".
HRMS_DOCUMENT_WARNING_DAYS=30

# When the nightly expiry scan runs (06:15 by default). Both are deployment
# mechanics, so they are env vars; the scan is idempotent regardless of when
# it fires, because expiry_notified_at lives on the row.
HRMS_DOCUMENT_SCAN_HOUR=6
HRMS_DOCUMENT_SCAN_MINUTE=15
```

**Critical:** `APP_DEBUG=false` in production. A debug page can leak env secrets.

**Critical:** only flip `PASSWORD_RESET_ENABLED=true` in the same commit as a
`MAIL_MAILER` that has been verified to deliver. The endpoint answers `501`
while it is `false`, and `config:cache` bakes the value in — changing it needs
a `php artisan config:clear`.

**Critical:** do **not** rotate `APP_KEY` as part of a routine `.env` refresh.
Since Phase 10 every column of `employee_bank_accounts` is an encrypted cast,
and ciphertext written with the old key is unreadable with a new one — a
rotation that skips re-encrypting that table silently destroys every IBAN.
Rotate only as a deliberate two-step (decrypt with the old key, re-encrypt
with the new), with the table backed up first. See `docs/SECURITY.md` §4.10.

### 3.2 Post-deploy commands

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan storage:link        # only if any public disk is used
```

**When the permission catalogue grows** (it has six times — Phases 4, 6, 7,
8, 9 and 10, and it now stands at **80 permissions / 401 grants**), re-run the
idempotent seeders rather than the whole `DatabaseSeeder`:

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

**Phase 10 added two configuration seeders** — run them on first deploy of
this release, before anybody opens the Documents screen:

```bash
php artisan db:seed --class=DocumentTypeSeeder --force         # 9 types, keyed by `code`
php artisan db:seed --class=OnboardingRequirementSeeder --force # 8 requirements, all mandatory
```

Both are keyed by a **UNIQUE `code`** rather than by an id, so re-running
them updates a row it already wrote instead of duplicating it — and
`DocumentTypeSeeder` refuses to write a requirement whose document type is
missing rather than leaving a null `document_type_id` behind. Without them
the API still starts, but `GET /document-types` offers nothing to file
against and every onboarding checklist reads *missing*.

(Phase 9's `ExpenseCategorySeeder` is the same shape: six categories, keyed
by `code`, safe to re-run.)

**Phase 10 adds no new `settings` rows** — its warning windows, scan time
and upload ceiling are the `HRMS_DOCUMENT_*` env vars in §3.1, because they
are deployment mechanics, while *which* type warns early stays a column on
`document_types`.

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
| `ScanDocumentExpiries` | Flip every lapsed document to **expired**, and raise `DocumentExpiring` / `DocumentExpired` **once per document per window** | **daily at 06:15** — `HRMS_DOCUMENT_SCAN_HOUR` / `HRMS_DOCUMENT_SCAN_MINUTE` | ✅ Phase 10 — **scheduled but delivering nothing yet**: both events have no subscriber (no FCM, §8/§11), so today the expiry report is how HR sees it |
| Training certificate expiry | Notify employee + HR | Daily | ⬜ **cut from Phase 10's approved scope — unscheduled** |
| Leave reminders | Pending approvals, upcoming leave | Daily | ⬜ with notifications |
| Attendance reminders | Missing check-in / check-out | Daily | ⬜ with notifications |
| Payroll processing | Monthly run | — | ✅ Phase 8 built it, and **deliberately did not schedule it**: a run is an explicit `POST /payroll/process` behind `payroll.process`. Money should not move on a timer; the one-way ladder already makes an accidental second run cost `updated: 0` |
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

**The expiry scan is guarded the same way, and its idempotency is in the
data too.** `ScanDocumentExpiries` writes `expiry_notified_at` on the row
*before* it raises an event, so a second pass in the same window finds the
marker and raises nothing — which means two overlapping workers, a doubled
cron entry or a manual `schedule:test` run cannot make HR's inbox (once
notifications exist) say the same passport twice. `expiry_notified_at`
clears itself when the document is re-dated, so a renewed passport earns
exactly one fresh warning.

Verify both jobs are registered:

```bash
$ php artisan schedule:list
17 * * * *  App\Jobs\EnforceSickCertificateDeadlines .... Next Due: …
15 6 * * *  App\Jobs\ScanDocumentExpiries ............... Next Due: …
```

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
├── employee-documents/{employeeId}/{uuid}.{ext}  Phase 10 — images re-encoded,
│                                                 PDFs kept exactly as sent
├── expense-receipts/{expenseId}/{uuid}.{ext}     Phase 9, kept exactly as sent
└── (no payroll directory — see below)
```

`employee-documents/` is the largest of them and the only one holding
identity papers. Its name is `HRMS_DOCUMENT_DIRECTORY` (§3.1); the **stored
name is always minted by the server** as `{employeeId}/{uuid}.{ext}`, so a
client filename — and anything path-like inside it — never becomes part of a
path. The extension on disk follows the sniffed bytes, not the name.

There is **no `reports/` directory and no stored PDF.** The daily site
report's document is rendered on demand by `GET /daily-site-reports/{id}/pdf`
and streamed, so nothing to back up, nothing to expire, and no path a
response could leak.

**Phase 8 created no directory at all.** A salary slip is the `payrolls` row
rendered by `GET /salary-slips/{id}/pdf` and a certificate is
`GET /salary-certificate-requests/{id}/pdf`; both stream and close. There is
no `salary_slips` table, no `pdf_path` column and no file to restore — which
means a **backup restore cannot resurrect somebody's payslip from an old
copy of the disk**, and the restore procedure below has nothing extra to
handle. The only place a Phase 8 PDF ever touches a file system is a
*client's* private temp directory, deleted by the OS, on the phone.

Never inside the web root. Every live directory is reachable only
through an authenticated route that runs a policy — `GET /attendance/{id}/selfie`,
`GET /leave/{id}/certificate`,
`GET /daily-site-reports/{id}/photos/{photo}` (plus its activity-report
twin), `GET /employee-documents/{id}/file` and
`GET /expenses/{expense}/receipts/{receipt}` — and all answer
`Cache-Control: no-store`. No response body ever
contains a filesystem path, so there is no URL to leak even if a JSON
payload is logged somewhere.

`selfie_directory`, `certificate_directory`, `report_photo_directory` and
`document_directory`
come from `config/hrms.php` (`HRMS_SELFIE_DIRECTORY`,
`HRMS_CERTIFICATE_DIRECTORY`, `HRMS_REPORT_PHOTO_DIRECTORY`,
`HRMS_DOCUMENT_DIRECTORY`) — change them
there, not by moving the folders afterwards.

**The employee file is the one directory worth an extra restore check.**
It holds passports, Emirates IDs, visas and contracts: back it up with the
same nightly pass as the rest of `storage/app/private`, and treat a restore
as a *security* event as well as an operational one — the bytes are
identifiable documents, and the database knows which employee each belongs
to while the filenames deliberately do not.

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

**Never log a payroll figure either.** A `net_salary`, a loan principal or a
per-employee line out of a run must never reach `laravel.log`, a debug dump
or a query log line with its bindings shown. An aggregated log has a much
longer retention than the HR system it came from and is often readable by
people who hold no permission at all — a payroll run is identified in this
codebase by *period and counts*, never by *who earned what*. This is a rule
to keep, not a control that enforces itself: no payroll path calls `Log::info()`
today, and adding one is a decision someone must make deliberately.

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
| Phase 8 payroll settings documented in §3.1 (three `settings` rows, **not** env vars) | ✅ |
| Phase 8 adds **no** storage directory and stores **no** PDF (§7.1) | ✅ by design — nothing extra to back up, restore or expire |
| "Never log a payroll figure" recorded as a rule (§9) | ✅ documented; no payroll path calls `Log::info()` today |
| Permission catalogue documented at its current size (§3.2) | ✅ 80 permissions / 401 grants, six phases of growth |
| Phase 10 document env keys documented in §3.1 (`HRMS_DOCUMENT_*`) | ✅ |
| Phase 10 document env keys present in `.env.example` | ✅ added with the phase — no secrets, defaults only |
| Phase 10 storage directory documented (§7.1) | ✅ `employee-documents/{employeeId}/{uuid}.{ext}`, minted server-side |
| Phase 10 configuration seeders documented (§3.2) | ✅ `DocumentTypeSeeder` · `OnboardingRequirementSeeder`, both keyed by UNIQUE `code` and safe to re-run |
| Expiry scan scheduled and registered (§5) | ✅ `ScanDocumentExpiries`, daily 06:15, idempotent through `expiry_notified_at` |
| **`APP_KEY` rotation hazard recorded (§3.1)** | ✅ **documented** — `employee_bank_accounts` is on encrypted casts; rotating without re-encrypting destroys every IBAN. See `docs/SECURITY.md` §4.10 |
| Statutory (UAE / jurisdiction) overtime rate validated and configured | ⬜ **required before production** — the multiplier is a generic engine setting, not compliance |
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
