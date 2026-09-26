# Deployment

> **Status:** Phase 1 — Foundation. Nothing is deployed yet. This document defines the
> target production architecture, environment requirements, backup strategy and the
> runbook for each phase.

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
```

**Critical:** `APP_DEBUG=false` in production. A debug page can leak env secrets.

### 3.2 Post-deploy commands

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan storage:link        # only if any public disk is used
```

---

## 4. Queue Worker

Queue handles notifications, PDF generation and report exports.

**systemd unit** `/etc/systemd/system/hrms-worker.service`:

```ini
[Unit]
Description=HRMS Laravel Queue Worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/hrms/backend
ExecStart=/usr/bin/php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
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

---

## 5. Scheduler (cron)

Business rules that **must** run server-side — never depend on the mobile app being open:

| Job | Purpose | Default schedule |
|---|---|---|
| Sick certificate deadline | Convert missing-cert leave to **LOP** | Daily |
| LOP conversion | Finalise pending LOP entries | Daily |
| Document expiry reminders | Passport / visa / Emirates ID / certificates | Daily |
| Training certificate expiry | Notify employee + HR | Daily |
| Leave reminders | Pending approvals, upcoming leave | Daily |
| Attendance reminders | Missing check-in / check-out | Daily |
| Payroll processing | Monthly run | Monthly |
| Notification dispatch | FCM delivery | Every minute |

**Cron entry** (`crontab -e` for the deploy user):

```cron
* * * * * cd /var/www/hrms/backend && php artisan schedule:run >> /dev/null 2>&1
```

Verify with:
```bash
php artisan schedule:list
```

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
├── selfies/                 attendance photos
├── documents/employees/     passports, IDs, visas, contracts
├── documents/payroll/       salary slips, certificates
└── reports/                 generated PDFs
```

Never inside the web root.

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
| Queue failures | `failed_jobs` table + alert |
| Scheduler missed runs | `schedule:run` heartbeat monitoring |
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

# 6. restart workers
sudo systemctl restart hrms-worker

# 7. verify
php artisan about
curl -s https://api.example.com/api/v1/health
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
| Server provisioning | ⬜ |
| CI/CD pipeline | ⬜ |
| SSL certificate | ⬜ |
| Backup script implemented | ⬜ |
| Restore tested | ⬜ |
