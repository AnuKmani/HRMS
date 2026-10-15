# Hosting the HRMS backend (staging / demo URL)

The process is the same whatever the host:

1. Host the Laravel backend → you get a URL, e.g.
   `https://hrms-staging.laravel.cloud`
2. The API is that URL + `/api/v1`, e.g.
   `https://hrms-staging.laravel.cloud/api/v1`
3. Put it in the app's env file and build the APK:
   - React Native (`mobile-rn/.env`): `EXPO_PUBLIC_API_URL=https://hrms-staging.laravel.cloud/api/v1`
   - Flutter: `--dart-define=API_BASE_URL=https://hrms-staging.laravel.cloud/api/v1`

No domain is needed for staging: the host gives a free address with HTTPS.
Add your own domain later (e.g. `api.yourcompany.com`) and change only the
env file.

## Recommended: Laravel Cloud

Gives MySQL, a queue worker, the scheduler and object storage — everything
this backend needs.

1. Sign up at https://cloud.laravel.com and connect GitHub.
2. New application → repository `AnuKmani/HRMS`. The Laravel app is in the
   `backend/` folder: if Cloud asks for the app's root directory, choose
   `backend`. If it only deploys from the repository root, create a
   backend-only repository with:
   `git subtree split --prefix backend -b backend-only` and push that branch
   to a new repository.
3. Add a **MySQL database** and attach it (Cloud sets the `DB_*` values).
4. Add an **Object storage bucket** and attach it (Cloud sets the `AWS_*`
   values). Then set `PRIVATE_FILES_DRIVER=s3` so selfies, documents and
   certificates are kept in the bucket — the server's own disk is wiped on
   every deploy.
5. Environment variables (Settings → Environment):
   ```
   APP_NAME=HRMS
   APP_ENV=staging
   APP_DEBUG=false
   APP_KEY=            # php artisan key:generate --show
   PRIVATE_FILES_DRIVER=s3
   QUEUE_CONNECTION=database
   MAIL_MAILER=log                     # until real MAIL_* is set up
   MALWARE_SCANNER_MODE=disabled       # ClamAV is not available on Cloud
   ```
6. Deploy commands:
   ```
   php artisan migrate --force
   php artisan db:seed --force          # first deploy only
   php artisan permission:cache-reset
   php artisan optimize
   ```
7. Turn on the **queue worker** (emails, push notifications) and the
   **scheduler** (sick-certificate check → loss of pay).
8. First logins: run once from Cloud's command runner
   `php artisan hrms:uat-accounts --password=Uat-pass-123`

## Vercel

Possible with the community PHP runtime, but it has no queue worker, no
persistent disk and no MySQL: you would need an external MySQL, a bucket
(`PRIVATE_FILES_DRIVER=s3`) and an external cron for the scheduler.
Laravel Cloud (or a small VPS with the scripts in `deployment/`) is simpler
and more reliable for this backend.
