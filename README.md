# HRMS — Advanced HRMS + Multi-Site Workforce Management System

A production-oriented **Human Resource Management System** built for an **MEP / Construction company** with multiple projects and multiple physical sites.

---

## 1. Project Purpose

Construction and MEP companies have a workforce that does not sit at one desk. An employee may have a **primary site**, be **temporarily assigned** to another, or **move between several sites in a single workday**. A generic HRMS does not model this.

This system is built around that real-world reality:

| Capability | Why it matters |
|---|---|
| **Multi-site workforce** | Employees are assigned to sites historically, not overwritten |
| **GPS + geofence attendance** | Check-in only accepted inside the configured site radius |
| **Selfie attendance** | Proof of presence at the site, captured on the front camera |
| **Site visits / movement** | Records travel between sites during the workday |
| **Offline-first attendance** | Construction sites often have poor connectivity |
| **Configurable rules** | Working hours, grace period, OT threshold, geofence radius, leave rules — none hard-coded |
| **Full payroll cycle** | Attendance + approved overtime + LOP feed payroll |
| **Document expiry tracking** | Passport, visa, Emirates ID, certificates with scheduled reminders |

---

## 2. Technology Stack

### Frontend — Flutter

| Item | Value |
|---|---|
| Framework | **Flutter** (Dart) |
| Design system | **Material 3** |
| Target | Android first, architecture ready for iOS |
| State management | **Riverpod** |
| Routing | **GoRouter** |
| HTTP | **Dio** |
| Secure token storage | `flutter_secure_storage` (Keychain / Keystore) |
| Local database (offline) | **Drift** (SQLite) |
| Camera / location | `camera`, `geolocator` |
| Push notifications | **Firebase Cloud Messaging** |
| PDF generation | `pdf` |

**Why Flutter?** One codebase for Android now and iOS later, first-class camera/GPS access, and strong offline database support — all required for site-based attendance.

### Backend — Laravel

| Item | Value |
|---|---|
| Framework | **Laravel** (PHP 8.2+) |
| Interface | **RESTful API** (`/api/v1/`) |
| Authentication | **Laravel Sanctum** (token-based) |
| RBAC | `spatie/laravel-permission` (roles + granular permissions) |
| Audit logging | `spatie/laravel-activitylog` |
| PDF | `barryvdh/laravel-dompdf` |
| Architecture | Controllers → **Services** (business rules) → Models |

**Why Laravel?** All critical business rules — geofence validation, leave balance, LOP conversion, payroll calculation, authorization — must be enforced **server-side**. The mobile app is never trusted with these decisions.

### Database — MariaDB

- **MariaDB 10.4.28** — host `127.0.0.1`, port `3306` (bundled with XAMPP)
- Application database **`hrms_laravel`**, accessed by the dedicated user **`hrms_app`** — never `root`
- Normalized schema with primary keys, foreign keys, indexes, unique constraints
- Soft deletes where records must be recoverable
- Timestamps on all tables

**Why MariaDB?** Mature, reliable, well-understood, and easy to back up and restore.

### Notifications — Firebase Cloud Messaging (FCM)

- Push notifications for approvals, reminders, expiry warnings, payroll availability
- Delivery is handled **server-side** by Laravel via queued jobs
- The Flutter app only registers/unregisters device tokens

### Private File Storage

Selfies, passports, Emirates IDs, visas, contracts and salary documents are stored in **private storage** and served only through **authorized, temporary/signed URLs**. They are never placed in a public directory.

---

## 3. Architecture Overview

```
┌────────────────────┐
│   Flutter Mobile   │  Riverpod → Repository → Dio
└─────────┬──────────┘
          │ HTTPS
┌─────────▼──────────┐
│  Laravel REST API  │  Route → Controller → FormRequest
└─────────┬──────────┘         ↓
          │              Service (business rules)
          │                     ↓
          │              Policy (authorization)
          │                     ↓
┌─────────▼──────────┐   API Resource (response shape)
│     MariaDB        │
└─────────┬──────────┘
          │
┌─────────▼──────────┐
│ Private File Store │  + Queue Worker + Scheduler + FCM
└────────────────────┘
```

**Non-negotiable rule:** the Flutter app may *display* distance-from-site for user experience, but **Laravel performs the final geofence validation**. Client-side GPS is never trusted.

---

## 4. Folder Structure

```
HRMS/
│
├── mobile/                  # Flutter application
│   ├── lib/
│   │   ├── core/            # config, network, router, storage
│   │   │   ├── config/      #   app_config.dart (--dart-define base URL)
│   │   │   ├── network/     #   api_client.dart, api_exception.dart
│   │   │   ├── router/      #   app_router.dart (GoRouter + auth guard)
│   │   │   └── storage/     #   token_store.dart, device_identity.dart
│   │   ├── features/
│   │   │   ├── auth/        #   models, repository, controller, login, splash
│   │   │   ├── home/        #   permission-gated module tiles
│   │   │   ├── employees/   #   departments, designations, projects, sites…
│   │   │   ├── attendance/  #   today screen, geofence, selfie, offline queue
│   │   │   ├── leave/       #   requests, approval chain, certificate, balances
│   │   │   ├── timesheet/   #   derived working-day snapshots
│   │   │   ├── overtime/    #   claims, approval chain, payroll eligibility
│   │   │   └── holidays/    #   holiday calendar (public / company / site)
│   │   ├── app.dart         # MaterialApp.router
│   │   └── main.dart        # ProviderScope + runApp
│   └── test/                # fakes/, app_test.dart, feature + unit tests
│
├── backend/                 # Laravel REST API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/   # Auth, org, attendance, leave, timesheets, overtime
│   │   │   ├── Requests/             # Form Requests for every write endpoint
│   │   │   ├── Resources/            # ApiResponse-backed resource classes
│   │   │   └── Responses/            # ApiResponse — the response envelope
│   │   ├── Models/          # Eloquent models
│   │   ├── Services/        # Business logic (leave days, approval chain, timesheets…)
│   │   ├── Policies/        # Authorization — 30 policies (always named `<Model>Policy`)
│   │   ├── Support/         # Visibility scoping, Geo (haversine), ClientInput
│   │   ├── Jobs/            # Queued work
│   │   └── Notifications/   # FCM + database notifications
│   ├── config/
│   │   ├── hrms.php         # visibility lists + attendance/storage config
│   │   └── rate_limiting.php   # login, password-reset, attendance limits
│   ├── database/migrations/
│   ├── routes/api.php
│   └── tests/               # Feature + Unit (553 tests, 3674 assertions)
│
├── docs/                    # Project documentation
│   ├── ARCHITECTURE.md
│   ├── DATABASE.md
│   ├── API_DOCUMENTATION.md
│   ├── FLUTTER_GUIDE.md
│   ├── DEPLOYMENT.md
│   ├── SECURITY.md
│   ├── TESTING.md
│   └── USER_GUIDE.md
│
├── scripts/                 # Local dev helpers (start the database, etc.)
│
├── README.md                # This file
└── .gitignore
```

---

## 5. User Roles

Access is controlled by **roles** *and* **granular permissions**. Hiding a button in Flutter is not authorization — Laravel enforces every rule.

| # | Role |
|---|---|
| 1 | Super Admin |
| 2 | HR Admin |
| 3 | HR Executive |
| 4 | Payroll Admin |
| 5 | Project Manager |
| 6 | Site Engineer |
| 7 | Site Supervisor |
| 8 | Finance |
| 9 | Management |
| 10 | Employee |

Example permissions: `employees.view`, `employees.create`, `attendance.manage`, `leave.approve`, `payroll.manage`, `sites.manage`, `audit.view`.

**Implemented:** **95 permissions** (462 role grants), all named
`resource.action`, seeded by `RoleSeeder` + `PermissionSeeder` +
`RolePermissionSeeder`. Super Admin holds every permission; each other role
is an explicit allow-list, so anything absent is denied.
Phase 2 seeded the first 40; Phase 5 only *granted* an existing one
(`attendance.view`); Phase 6 added `approvals.view/manage`, `leave.balance.view/manage`,
`holidays.manage`, `timesheets.view/manage` and `overtime.view/create/approve/manage`,
and retired `leave.request` in favour of `leave.create`; Phase 7 added the
eight site-report permissions; **Phase 8 added eleven** —
`loans.{view,create,approve,manage}`, `salary_slips.{view,manage}`,
`salary_certificates.{view,manage}` and the three payroll verbs
`payroll.process`, `payroll.lock`, `payroll.summary.view`; **Phase 9 added
three** — `expenses.create`, `expenses.update` and `expenses.receipts.view`
(the other three `expenses.*` gates, `view`, `approve` and `manage`, already
existed), taking the catalogue to 73 and the grants to 364. **Phase 10 added
seven** — `documents.{create,update,verify,delete,expiry.view,manage}` (six,
with `documents.view` already in the catalogue) and the new
`onboarding.{view,manage}` pair, taking it to **80 permissions** and
**401 grants**. Managers keep `documents.view` without `documents.manage`:
supervising a person must not open their passport. **Phase 11 added
fifteen** — the eight `training.*` verbs (`view`, `create`, `update`,
`manage`, `assign`, `complete`, `certificates.view`, `expiry.view`) and the
seven `assets.*` verbs (`view`, `create`, `update`, `manage`, `assign`,
`return`, `history.view`), taking the catalogue to **95 permissions** and
**462 grants**. `assets.view` alone reads your own kit at cost-free, and
`assets.history.view` — not `assets.view` — is who has held *what*.
See [`docs/SECURITY.md`](docs/SECURITY.md) §3.1 for the full catalogue and the
middleware used to enforce it server-side.

---

## 6. Getting Started

### Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.2+ | with `pdo_mysql`, `openssl`, `curl`, `mbstring`, `zip`, `gd`, `intl`, `bcmath`, `fileinfo` |
| Composer | 2.x | |
| MariaDB | 10.4.28 | Bundled with XAMPP; connects on `127.0.0.1:3306` |
| Git | 2.x | |
| Flutter | stable | **Installed on a drive with adequate free space** |
| JDK | 17 | Required for Android builds |
| Android SDK | API 35 | With `platform-tools` and `build-tools` |

> **Disk space note:** The Flutter SDK, Android SDK, JDK, Gradle cache and pub cache together need roughly **8–12 GB**. These are installed on a secondary drive (`F:`), *not* on the system drive, to avoid filling the primary disk.

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# configure DB_* in .env
php artisan migrate --seed        # 55 migrations, 62 tables + RBAC/settings seed data
php artisan serve          # http://127.0.0.1:8000
```

> **Two background processes are required — `serve` alone is not the app.**
>
> ```bash
> # Terminal 2: the queue worker. Without it, EnforceSickCertificateDeadlines
> # is dispatched and then sits in the `jobs` table forever.
> php artisan queue:work --stop-when-empty
>
> # Terminal 3 (or a cron line) — dispatches scheduled work:
> php artisan schedule:run
> ```
>
> Neither starts itself, and neither failure is visible from the app: an idle
> queue and a missing cron both look identical to a healthy install until
> somebody's sick leave stays `pending` past its deadline. In production both
> run under **Supervisor or systemd** plus a `* * * * * … schedule:run` cron
> entry — see [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) §4–§5.
>
> `.env.example` now carries every HRMS key an operator may want to tune
> (selfie and certificate limits, geofence bounds, rate limits, scheduler
> tick). All of them are **non-secret** — limits and directory names, never
> credentials. Business rules such as the 2-day certificate deadline are
> database settings, not environment variables.

```bash
php artisan test                  # 553 tests, 3674 assertions
./vendor/bin/pint                 # code style
```

> **Two databases — development vs testing**
>
> | Purpose | Database | Configured in |
> |---|---|---|
> | Development | `hrms_laravel` | `backend/.env` |
> | Testing | `hrms_testing` | `backend/phpunit.xml` |
>
> `RefreshDatabase` runs `migrate:fresh` (drops every table), so tests are pointed at
> `hrms_testing` to keep local data safe. Running `php artisan test` does **not** wipe
> `hrms_laravel` — no re-seed needed afterwards. See
> [`docs/DATABASE.md`](docs/DATABASE.md) §"Development vs testing database".

### Frontend

Start the API first (see **Backend** above), then in a second terminal:

```bash
cd mobile
flutter pub get

# Default base URL is http://10.0.2.2:8000/api/v1 — that is how the Android
# emulator reaches the host machine, so `flutter run` needs no arguments there.
flutter run

# On a physical device (or any backend not on this machine):
flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1
```

```bash
flutter analyze              # must report no issues
flutter test                 # 378 tests, no device or server required
```

> The base URL is compiled in with `--dart-define`. There is no config file to
> leak and no runtime setting that could repoint a device at an attacker's
> server.

---

## 7. Documentation

| File | Contents |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | System design, folder layout, key decisions |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Schema, tables, relationships, ERD notes |
| [`docs/API_DOCUMENTATION.md`](docs/API_DOCUMENTATION.md) | Endpoints, request/response formats |
| [`docs/FLUTTER_GUIDE.md`](docs/FLUTTER_GUIDE.md) | Flutter patterns, concepts, how features are built |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Server setup, queue worker, scheduler, backups |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Auth, RBAC, file access, secrets handling |
| [`docs/TESTING.md`](docs/TESTING.md) | Test strategy and how to run tests |
| [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) | End-user workflows by role |

---

## 8. Current Status

### ✅ Phase 1 — Foundation (COMPLETE)

| Step | Status |
|---|---|
| Workspace inspection | ✅ Complete |
| Environment inspection (PHP, Composer, MariaDB, Git) | ✅ Complete |
| Git repository initialized | ✅ Complete |
| Monorepo folder structure | ✅ Complete |
| Root `.gitignore` | ✅ Complete |
| Root `README.md` | ✅ Complete |
| `docs/` documentation set | ✅ Complete |
| MariaDB database + dedicated `hrms_app` user | ✅ Complete |
| Laravel 12 API skeleton + Sanctum (`/api/v1/`) | ✅ Complete |
| Flutter toolchain install (on `F:`) | ✅ Complete |
| Flutter project skeleton (`mobile/`) | ✅ Complete |

### ✅ Phase 2 — Core database schema + RBAC (COMPLETE)

| Deliverable | Status |
|---|---|
| `spatie/laravel-permission` **^6.25** (the release line compatible with PHP 8.2 + Laravel 12) | ✅ |
| 10 roles · 95 permissions · 462 role-permission grants (the count grew as Phases 4–11 added permissions; `PermissionSeeder::PERMISSIONS` is the single source of truth) | ✅ |
| Middleware aliases `permission` / `role` / `role_or_permission` registered | ✅ |
| `settings` table + `SettingsService` — 12 seeded business rules | ✅ |
| `departments`, `designations` | ✅ |
| `employees` — structured names, 5-value status vocabulary, unique constraints | ✅ |
| `projects`, `sites` — DECIMAL geofence, per-site radius, status vocabularies | ✅ |
| `shifts` — overnight `crosses_midnight` handling + duration maths | ✅ |
| `employee_site_assignments` — append-only, `RESTRICT` FKs | ✅ |
| Seeders: roles, permissions, role mappings, settings, dev structure | ✅ |
| **10 migrations · 23 tables · all `Ran`** | ✅ |
| Tests: **75 passed (310 assertions)** | ✅ |
| Docs updated: `DATABASE.md`, `ARCHITECTURE.md`, `SECURITY.md` | ✅ |

> **Deliberately not built in Phase 2:** attendance, leave, payroll, expenses,
> documents, notifications, authentication endpoints, and Flutter feature screens —
> these belong to Phases 3–12.

### ✅ Phase 3 — Authentication + Flutter auth foundation (COMPLETE)

**Backend**

| Deliverable | Status |
|---|---|
| `POST /auth/login` — Sanctum bearer token, explicit `Hash::check` (stateless, no session row) | ✅ |
| `GET /auth/me` · `POST /auth/logout` · `POST /auth/change-password` | ✅ |
| `GET /auth/sessions` · `DELETE /auth/sessions/{id}` — device management | ✅ |
| `POST /auth/forgot-password` · `POST /auth/reset-password` — **501 until a real mailer is configured** | ✅ |
| `GET /roles` gated by `permission:roles.view` | ✅ |
| `users.status` column (indexed) — deactivated accounts cannot sign in | ✅ |
| Response envelope built in one place: `App\Http\Responses\ApiResponse`; `data` and `errors` always JSON **objects** | ✅ |
| Exception rendering ordered most-specific-first, each branch guarded by `$request->is('api/*')` | ✅ |
| Rate limiting — `login` 5/min/IP · `password_reset` 5/15min/IP, numbers in `config/rate_limiting.php` | ✅ |
| Form Requests + API Resources (`UserResource`, `EmployeeBriefResource`, `RoleResource`) | ✅ |
| Generic user resource carries **no** salary or private HR data | ✅ |
| Tests: **120 passed (517 assertions)** · `pint --test` clean · `composer validate` valid | ✅ |

**Flutter**

| Deliverable | Status |
|---|---|
| Riverpod 3 `Notifier` auth controller — `restoring / unauthenticated / authenticating / authenticated / failed` | ✅ |
| Dio client — bearer interceptor, envelope → `ApiException`, `Retry-After` parsing | ✅ |
| `flutter_secure_storage` token store + per-install device identity | ✅ |
| GoRouter guard driven by `refreshListenable` — the router is never rebuilt on auth change | ✅ |
| Splash (`/`, session restore), login screen, home placeholder | ✅ |
| Server 422 field errors drawn with `forceErrorText`; local validators stay local | ✅ |
| `flutter analyze` clean · `flutter test` **43 passed** | ✅ |

> **Deliberately not built in Phase 3:** employee CRUD, attendance, leave,
> payroll, documents, expenses, notifications and the real dashboard — Phases
> 4–12. Password-reset emails are implemented and tested but answer **501**
> until `PASSWORD_RESET_ENABLED=true` alongside a mailer that can actually
> deliver; `MAIL_MAILER=log` writes the link where nobody would read it, and
> claiming success would be a lie. See [`docs/SECURITY.md`](docs/SECURITY.md) §2.
>
> **Known caveat:** `logout()` swallows a network failure and signs out locally
> anyway. The stored token is cleared, but until the server is reachable again
> the remote session still exists — there is no retry queue, because
> re-sending a token whose purpose is to delete itself has no safe home to
> live in. Covered honestly in [`docs/SECURITY.md`](docs/SECURITY.md) §2.

### ✅ Phase 4 — First vertical slice (COMPLETE)

Departments, designations, employees, projects, sites and employee-site
assignments: Form Requests, API Resources, services with transactions, six
policies, `employees.salary.view` separated from `employees.view`, plus the
Flutter `data / domain / presentation` features behind a permission-gated
home. Tests **190 passed (993 assertions)** after Phase 4.

### ✅ Phase 5 — GPS attendance and site movement (COMPLETE)

| Deliverable | Status |
|---|---|
| `attendances` + `site_visits` — 2 migrations, `DATETIME` (not `TIMESTAMP`), `UNIQUE (employee_id, attendance_date)` and four `client_event_id` unique keys | ✅ |
| `GeofenceService` — coordinates, accuracy ceiling, per-site radius; **distance always computed, never accepted from the client** | ✅ |
| `WorkingTimeCalculator` — late / working / break / overtime / early, normal **and** overnight, no arithmetic in controllers | ✅ |
| `AttendanceStatusCalculator` — the only writer of `present` / `late` / `incomplete` / `missing_checkout` / `manually_adjusted` | ✅ |
| `POST /attendance/check-in` · `check-out` · `GET /attendance/today` · `GET /attendance` · `GET /attendance/{id}` · `GET /attendance/{id}/selfie` | ✅ |
| `POST /site-visits/start` · `{id}/end` · `GET /site-visits/today` · `GET /site-visits` · `GET /movement/today` | ✅ |
| `AttendancePolicy` + `SiteVisitPolicy` — self-service routes carry **no** `permission:` middleware; row scope fails closed | ✅ |
| Private selfies (`storage/app/private/attendance-selfies/{employeeId}/{uuid}.jpg`), streamed only through the policy, `no-store`, no paths in JSON | ✅ |
| **Sanitised on write** — `SelfieSanitizer` decodes with PHP GD and re-encodes as JPEG 85, so EXIF/GPS/camera metadata and the client filename never reach the disk; `ImageContent` rule rejects non-images and over-budget pixels at the request | ✅ |
| Offline: `client_event_id` idempotency, replay through the same route, full re-validation on sync | ✅ |
| Rate limit `attendance` 30/min/user on the four writes | ✅ |
| Flutter `features/attendance/` — 6-state location flow, 5-state camera flow, advisory local geofence, offline queue with **Sync now** | ✅ |
| Android manifest: fine/coarse location + camera, **no** background location | ✅ |
| Tests: backend **321 passed (1451 assertions)** · Flutter **176 passed** · `pint --test` clean · `dart format` + `flutter analyze` clean | ✅ |
| Docs updated: `API_DOCUMENTATION`, `ARCHITECTURE`, `DATABASE`, `SECURITY`, `FLUTTER_GUIDE`, `TESTING`, `USER_GUIDE` | ✅ |

> **Deliberately not built in Phase 5:** attendance override and its audit
> trail (the `manually_adjusted` status is reserved with no writer),
> background/automatic sync (queueing is manual **Sync now**), site activity
> reports, and any login audit — see [`docs/SECURITY.md`](docs/SECURITY.md)
> §8. **Facial recognition is not built and is not planned.**

### ✅ Phase 5 hardening — server-side selfie sanitisation (COMPLETE)

Added after Phase 5 because the app-side EXIF strip could be walked around
by any client that speaks HTTP.

| Deliverable | Status |
|---|---|
| `SelfieSanitizer` — GD decode → alpha flattened onto white → **JPEG re-encode at quality 85**; only those bytes are written, the original upload never is | ✅ |
| Metadata removed as a side effect of re-encoding: no EXIF, no GPS fix, no camera make/model, no embedded thumbnail | ✅ |
| Library used: **PHP GD, already bundled** — no new Composer dependency | ✅ |
| `App\Rules\ImageContent` — header-level image check + pixel budget (`selfie_max_pixels`, default 4096×4096) as a field error | ✅ |
| Fail closed if the decoder is missing; server-minted `{uuid}.jpg`, client filename never read; private disk unchanged | ✅ |
| Access unchanged: `viewSelfie` policy, `no-store`, `401` without a session, `403` for a colleague | ✅ |
| Tests: `AttendanceSelfieSanitizationTest` (13) — valid JPEG, PNG re-encode, invalid, renamed non-image (fake **and** real sniff), oversized, over-budget pixels, EXIF stripped, original not stored, private, `401`, `403` | ✅ |
| Docs: `SECURITY` §4.3/§8/§13, `ARCHITECTURE` §5.5, `API_DOCUMENTATION` §4, `TESTING` §3.3/§6 | ✅ |

> **Still deliberately not built:** facial recognition, and **audit
> logging** — which remains scheduled for the dedicated audit/security
> phase and is not partially faked. See [`docs/SECURITY.md`](docs/SECURITY.md) §8.

### ✅ Phase 6 — Leave, timesheets and overtime workflows (COMPLETE)

The whole timekeeping half of the product: leave requests with a configurable
approval chain, balances, a holiday calendar, the sick-certificate → LOP rule,
derived timesheets, and overtime claims.

**Backend**

| Deliverable | Status |
|---|---|
| **9 migrations · 26 total · 34 tables · all `Ran`** — `approval_workflows`, `approval_workflow_steps`, `leave_types`, `leave_balances`, `leave_requests`, `holidays`, `timesheets`, `overtime_requests`, `approval_records` | ✅ |
| Configurable **leave types** (`entitlement_days`, `carry_forward_enabled`/`_limit`, `maximum_days_per_request`, `is_paid`, `requires_document`, `document_deadline_days`, `allow_negative_balance`, optional own workflow) — no leave rule is hard-coded in a service | ✅ |
| **Leave balances** written inside a DB transaction with a row lock; negative only when the leave type allows it | ✅ |
| `LeaveDayCalculator` — the one place day counts come from (weekends, holidays, halves); nothing else does date arithmetic | ✅ |
| Normalised request statuses `draft / pending / approved / rejected / cancelled / lop`, transitions only through service methods | ✅ |
| **Approval workflow engine** — `approval_workflows` + `approval_workflow_steps` (a `sequence`, no status column) + a materialised `approval_records` chain frozen at submit; approvers resolve as `reporting_manager` / `role` / `permission`, unresolvable steps are skipped with a remark and never deleted | ✅ |
| **Authorization**: own draft edit, **no self-approve**, approver only at the *current* step; coarse `permission:leave.approve` / `permission:overtime.approve` on the transition routes, everything else row-level in policies | ✅ |
| Sick **certificate upload** — private disk, `mimes`+`mimetypes`+config size, byte-level `CertificateContent` rule, server-minted filename, never a path in JSON | ✅ |
| Deadline defaults to **2 days** → converts to `lop` server-side with `lop_days`/`lop_reason`/`lop_applied_at`, the paid reservation released, and `LeaveConvertedToLop` dispatched after commit; `Schedule::job(EnforceSickCertificateDeadlines)->hourlyAt(config('hrms.scheduling.tick_minute'))` (default :17) with a queued, idempotent job (`$uniqueFor`). **No audit row and no notification — neither exists (SECURITY §8)** | ✅ |
| **Timesheets** as derived snapshots regenerated from attendance (`status = open / complete / incomplete`), own + manager-scoped lists, no approval endpoints | ✅ |
| **Overtime** through the same workflow engine; `payroll_eligible` set only on approval, no payroll arithmetic anywhere | ✅ |
| **7 policies** (`LeaveRequest`, `LeaveBalance`, `LeaveType`, `Holiday`, `Timesheet`, `OvertimeRequest`, `ApprovalWorkflow`) + 15 form requests + 9 API resources | ✅ |
| Routes: `/leave` (+`submit/approve/reject/cancel/certificate`), `/leave-balances`, `/leave-types`, `/holidays`, `/timesheets` (+`generate`), `/overtime`, `/approval-workflows` | ✅ |
| **11 new permissions** → 51 total, 236 grants | ✅ |
| Tests: backend **383 passed (2017 assertions)** · `pint --test` clean · `composer validate` valid | ✅ |

**Flutter**

| Deliverable | Status |
|---|---|
| `features/leave`, `features/timesheet`, `features/overtime`, `features/holidays` — each in `data/` · `domain/` · `presentation/` | ✅ |
| Shared `StatusChip`, `CameraCaptureSheet`, `DeviceCamera` (front/back lens), `NoPermission`, `ApprovalStep` | ✅ |
| Certificate filing photographs the document with the **back** camera — no file-picker package added | ✅ |
| Permission gates on every list (`leave.view`, `timesheets.view`, `overtime.view`) that skip the request entirely, not just the widget | ✅ |
| 15 routes under `/leave`, `/leave-balances`, `/holidays`, `/timesheets`, `/overtime`; module tiles on the home screen | ✅ |
| Tests: Flutter **215 passed** · `dart format` · `flutter analyze` clean | ✅ |

> **Deliberately not built in Phase 6:** payroll, salary slips, loans, expenses,
> documents, onboarding, training, assets, notifications/FCM and full audit
> logging. `payroll_eligible` and `lop_days` are recorded as *facts* for that
> future phase — nothing computes money here.

### ✅ Phase 7 — Site activity reports & daily site reports (COMPLETE)

The reporting half of the field day: a supervisor's activity form with camera
photographs, and the one official report for a site-day.

| Deliverable | Status |
|---|---|
| **7 migrations · 33 total · 41 tables · all `Ran`** — `site_activity_reports`, `site_activity_report_photos`, `daily_site_reports` + the three child-row tables | ✅ |
| `SiteActivityReportService` / `DailySiteReportService` — **author and project derived from the bearer token**, never read from the payload; `draft → submitted` only; one official report per site-day enforced by `dsr_site_date_unique` | ✅ |
| `StoresPrivateImages` / `ReportPhotoStore` on the fail-closed `SelfieSanitizer` — re-encoded, EXIF discarded, a byte array that will not decode is refused rather than stored | ✅ |
| `DailySiteReportPdf` **rendered on demand with dompdf** (Phase 7 added `barryvdh/laravel-dompdf:^3.1` — the one PDF package in the app), `html()` separate from `response()` so content is testable | ✅ |
| 2 policies + `App\Support\Visibility` row scoping (`daily_site_reports.manage` reaches a PM's own projects) · **8 new permissions → 59 total, 278 grants** | ✅ |
| Routes: `/site-activity-reports`, `/daily-site-reports`, `/reportable-sites`, `/…/photos`, `…/{id}/pdf` — **18 new · 104 definitions / 109 registered** | ✅ |
| Flutter: `features/site_reports/` — GPS on submit, repeatable manpower/materials/equipment rows, camera photographs, local drafts, PDF download through `open_filex` | ✅ |
| Tests: backend **42 passed** (19 + 15 + 8) · Flutter **303 passed** | ✅ |

### ✅ Phase 8 — Payroll, loans & salary documents (COMPLETE)

The money half, built as one vertical slice and deliberately narrow: a ledger
for a month, the calculation that fills it, what a person owes, and two
documents. **Nothing in it duplicates a calculation that already existed** —
attendance, overtime, leave and LOP arithmetic are reused from Phases 5–6
through `AttendanceStatusCalculator`, `WorkingTimeCalculator`,
`LeaveDayCalculator` and `LeaveBalanceService`.

**Backend**

| Deliverable | Status |
|---|---|
| **7 migrations · 40 total · 48 tables · all `Ran`** — `payrolls` (UNIQUE `employee_id + payroll_year + payroll_month`), `payroll_items`, `allowances`, `payroll_adjustments`, `loans`, `loan_installments` (UNIQUE `loan_id + sequence`), `salary_certificate_requests`. **No `salary_slips` table** — a slip is the `payrolls` row rendered | ✅ |
| `App\Support\Money` — scale 2, PHP `round()` half away from zero, **no bcmath required**; every money column `DECIMAL`, **no float/double anywhere**; one formatter, one rounding rule | ✅ |
| `PayrollCalculationService` **outside any controller** — basic → allowances → overtime → bonus → gross → LOP → unpaid leave → loan → net, writing nothing and taking no HTTP object; `PayrollService` owns the transaction and the one-way ladder `draft → calculated → reviewed → processed → locked` | ✅ |
| Backend-authoritative: a second run reports `updated: 0` and never rewrites a decided row; a recalculation **releases an installment before it takes it again**, so a payment is taken once | ✅ |
| Configurable LOP divisor (`payroll.lop_divisor_mode` = `fixed`, `payroll.lop_divisor` = 30) and overtime multiplier (`payroll.overtime_rate_multiplier` = 1.5) — **generic engine settings, not validated statutory rates** | ⚠️ jurisdiction to validate before production |
| Private, on-demand PDFs with Phase 7's dompdf (no second package): `SalarySlipPdf`, `SalaryCertificatePdf`, `Cache-Control: no-store`, nothing stored, no URL, filename minted server-side | ✅ |
| Status flows: loans `draft → pending → approved → active → completed` (+ `rejected` / `cancelled`), installments `pending → partially_deducted → deducted` (never twice, see below), certificates `pending → approved → generated` (+ `rejected` / `cancelled`), adjustments `pending → approved` — **only `approved` enters payroll** | ✅ |
| **5 policies** (`Payroll`, `PayrollAdjustment`, `Allowance`, `Loan`, `SalaryCertificateRequest`) → **22 total**; **11 new permissions → 70 total, 332 grants**; **3 new settings → 16 total** (17 after the hardening pass below); 11 form requests | ✅ |
| Routes: 36 new — payroll (index/show/summary/process/recalculate/review/finalize/lock), salary slips, allowances, payroll adjustments, loans, salary certificate requests — **140 definitions / 145 registered**, 117 permission-gated | ✅ |
| Tests: backend **469 passed (2915 assertions)** — 13 `PayrollTest` + 11 `LoanTest` + 9 `SalaryDocumentTest` + **11 `PayrollRepaymentTest`** · `pint --test` clean · `composer validate` valid · `migrate:status` all `Ran` | ✅ |

**Flutter**

| Deliverable | Status |
|---|---|
| `features/payroll/` — ledger list (period picker, run report, summary card), detail with **exactly one** ladder step drawn from the server's `next_action`, and the salary-slip list | ✅ |
| `features/loans/` — schedule, progress, form with **no employee-id field**, detail with submit / cancel / decide | ✅ |
| `features/salary_certificates/` — ask, decide, export | ✅ |
| `core/presentation/money.dart` (`Money` / `MoneyText`, decimal string → minor units, never a `double`, **no `intl`**) and `core/presentation/pdf_opener.dart` (`PdfOpener`, lifted out of the report feature) | ✅ |
| Four home doors, each gated on its own permission; `payroll.summary.view` alone draws the totals card and **never builds the rows provider** | ✅ |
| 10 routes → **33 total**; **378 tests** (+75) · `dart format` · `flutter analyze` clean | ✅ |

> **Two caveats carried forward, both documented rather than hidden:**
>
> 1. **Filing a certificate for a colleague is API-only today.** The Flutter
>    form submits `purpose` + `request_date` for *you*; the API accepts an
>    `employee_id` so an HR desk can file on someone else's behalf, and that
>    path is covered by backend tests only.
> 2. **A loan balance larger than the month's pay no longer produces a
>    negative `net_salary`** — repayments are sized against
>    `payroll.minimum_net_salary` and only the part that fits is taken (the
>    remainder carries forward). Loss of pay and approved adjustments still
>    can, and are passed through unclamped on purpose: they describe work
>    that was or was not done.
>
> **Still deliberately not built:** employee documents, onboarding,
> training, assets, FCM notifications and full audit logging — plus
> statutory (UAE) overtime configuration **and any statutory reading of the
> net-salary floor**, which must be validated before production.

### ✅ Post-Phase 8 hardening — payroll financial safety (COMPLETE)

Two things a payroll must not do — pay a negative net because of a
repayment, and drop a payment it could not take — closed without adding a
route, a permission or a policy.

**Backend**

| Deliverable | Status |
|---|---|
| **`payroll.minimum_net_salary`** (4th payroll setting → **17 total**, default `0`, group `payroll`) read through `SettingsService` and clamped to `≥ 0` inside `PayrollCalculationService` — a *settings* row, not a constant, and not reachable by any grant | ✅ |
| Room formula: `room = gross − (LOP + approved adjustments) − floor`; each due installment takes `min(remaining, room)`, **oldest due date first**. Attendance and approved adjustments are never rewritten, so `gross − deductions = net` still holds | ✅ |
| **Partial installments** — 1 migration (`2026_09_29_140008`, the only schema change): `loan_installments.deducted_amount DECIMAL(12,2) DEFAULT 0`, backfilled on rows already `deducted`, plus a `partially_deducted` status; the remainder stays outstanding and is offered to the next run together with anything overdue | ✅ |
| Four figures kept apart: `amount` (scheduled) · `deducted_amount` (taken across runs) · `remaining_amount` (left on this row) · `loans.outstanding_balance` (left on the loan, moved only by what was **taken**) — all four in `LoanInstallmentResource` / `LoanResource`, and on the payslip line's `metadata` | ✅ |
| `RepaymentAllocation` value object carries scheduled / before / now / left from the calculation to both writers, so a payslip line and a loan balance cannot disagree | ✅ |
| Take-once under concurrency: `claimInstallment()` re-reads with `lockForUpdate()` and clamps to what is still outstanding; `releaseInstallments()` gives back **one run's share** from that run's own `payroll_items` lines, then `payroll_id` is recomputed via `holderOtherThan()` | ✅ |
| Recalculation from `draft`/`calculated` may re-cut a deduction; `reviewed` / `processed` / `locked` may not (409, or `skipped` on a re-run) — retuning the floor never re-runs a signed-off month | ✅ |
| `dueInstallments()` = `status ∈ {pending, partially_deducted} AND due_date ≤ period end` — overdue carries forward; a zero-actual installment writes **no** payslip line, stays `pending` and is retried | ✅ |
| Tests: **`PayrollRepaymentTest` 11 passed (148 assertions)** on `hrms_testing` — full take, floor-capped partial, LOP never rewritten, carry-forward, retry-not-skip, run-twice, release-then-reclaim, locked untouched, advance consistency, four-figure reporting; `SettingsTest` seeds the new key | ✅ |
| Validation: `php artisan test` **469 passed (2915 assertions)** · `migrate:status` all `Ran` (48 tables / **41 migrations**) · `composer validate` valid · `pint --test` clean | ✅ |

**Flutter**

| Deliverable | Status |
|---|---|
| `LoanInstallment` gains `deductedAmount` / `remainingAmount` (falls back to `amount` when a server predates the field), `statusPartially_deducted` → **"Partly deducted"**, `isPartial` | ✅ |
| Loan detail shows the remainder under the due date for a partial row, and **Next payment** uses `remainingAmount` rather than the schedule's figure | ✅ |
| `LoanResource.installments` wrapped in `LoanInstallmentResource::collection` (a nested model serialized to raw attributes was dropping every computed key) | ✅ |
| Tests: **378 passed** (+1 partial-installment widget test) · `dart format .` 0 changed · `flutter analyze` clean | ✅ |

> **Not delivered by this pass:** UAE statutory deduction rules and any
> statutory minimum-wage reading of the floor (both need validation before
> production), and audit logging — every payroll/loan mutation already runs
> through a service method, which is where that log will attach.

### ✅ Phase 9 — Expense management (COMPLETE)

The claims half of the money story: an employee files what they spent
against a project and a site, attaches the receipt, and the claim runs the
**same approval engine as leave and overtime** on a new `expense` subject —
step 1 the reporting manager, step 2 Finance/HR. Money is a **decimal string
end to end** (`DECIMAL(12,2)`, `App\Support\Money`, `Money::round()`), never a
float, and nothing here computes payroll: a claim records what was spent, it
does not price it.

**Backend**

| Deliverable | Status |
|---|---|
| **3 migrations · 44 total · 51 tables · all `Ran`** — `expense_categories`, `expenses`, `expense_receipts` | ✅ |
| **6 seeded categories** (`ExpenseCategorySeeder`) carrying the two rules a claim must honour — `requires_receipt` and `maximum_amount` (null = no ceiling) — read by the form *and* re-checked by `ExpenseService` on create, update and submit, so a screen that never showed the rule cannot get past it | ✅ |
| `ExpenseService` is the only writer of status: `draft → pending → approved \| rejected \| cancelled`, each of submit / approve / reject / cancel inside a DB transaction and **no direct status writes anywhere**; field failures → **422** with an `errors` map, illegal transitions → **409** with a `message` that names the state | ✅ |
| Identity is never a field — `employee_id` and `status` are **prohibited** in `StoreExpenseRequest`; the claimant comes from the bearer token, and site↔project consistency plus the claimant's posting/assignment are enforced server-side (`Visibility::mayClaimExpenseAt()`) | ✅ |
| Workflow: `ApprovalWorkflow::SUBJECT_EXPENSE` + `ApprovalRecord::TYPE_EXPENSE`, `ApprovalWorkflowService` union widened to `LeaveRequest\|OvertimeRequest\|Expense`, seeded **EXP-STD** — step 1 "Standard expense approval" (`reporting_manager`), step 2 "Finance / HR" (`permission: expenses.manage`) | ✅ |
| Rejection requires `remarks` — server-side in `ActOnExpenseRequest`, mirrored by Flutter's `reject(id, {required String remarks})` — and the remark is stored on the refused approval record, where the employee reads it back | ✅ |
| Receipts: child table `expense_receipts`, private storage `expense-receipts/{expenseId}/{uuid}.{ext}`, MIME/extension/size/content validated, max **5120 KB** (`HRMS_EXPENSE_RECEIPT_MAX_KB`), **10 per claim**. The API exposes only `ExpenseReceiptResource` — **no storage path, no file URL**; `url` is the id-based read route, and `uploaded_by` is the **user** id | ✅ |
| Config: `config/hrms.php` → `expense_receipt_directory` (`HRMS_EXPENSE_RECEIPT_DIRECTORY`) and `expense_receipt_max_kilobytes` (`HRMS_EXPENSE_RECEIPT_MAX_KB`) | ✅ |
| Routes: **13 new — 153 definitions / 158 registered**; `expenses/summary` declared before `expenses/{expense}` (the Phase 7 ordering rule); receipt routes gated coarsely by `expenses.view` with the fine rule in `ExpensePolicy::viewReceipt` (own claim, or `expenses.receipts.view` + read access to that claim) | ✅ |
| History & filtering: `GET /expenses` paginates and narrows by `status`, `employee_id`, `project_id`, `site_id`, `expense_category_id` and an inclusive date window, row-scoped by `Visibility` (an Employee reads their own claims and no others); `GET /expenses/summary` answers by-status, by-category and by-project totals — a queue screen, not analytics | ✅ |
| Permissions: **3 new → 73 total, 364 grants** — `expenses.create`, `expenses.update`, `expenses.receipts.view`; `expenses.view`, `expenses.approve`, `expenses.manage` already existed. 17 settings · 4 approval workflows (EXP-STD added) | ✅ |
| Tests: backend **499 passed (3251 assertions)** — 30 new (`ExpenseTest` 20 + `ExpenseReceiptTest` 10) on `hrms_testing` · `composer validate` valid · `vendor\bin\pint --test` **PASS** (375 files) | ✅ |

**Flutter**

| Deliverable | Status |
|---|---|
| `mobile/lib/features/expenses/` — `domain/` · `data/` · `presentation/`: `expense.dart`, `expense_category.dart`, `expense_receipt.dart`, `expense_repository.dart`, `api_expense_repository.dart`, `expenses_controller.dart` | ✅ |
| Screens: `expense_list_screen` (status filter), `expense_detail_screen` (history, chain, decide), `expense_form_screen` (no employee-id field, category rules shown before submit), `expense_receipt_capture_sheet` (camera) | ✅ |
| 4 new routes — `/expenses`, `/expenses/new`, `/expenses/:id`, `/expenses/:id/edit` → **58 `GoRoute` entries**; a Home door **Expenses** (`Icons.receipt_long_outlined`, "Claims, receipts and approvals", `/expenses`) gated on `expenses.view` | ✅ |
| Tests: **454 passed** (+76 over the 378 baseline) · `dart format .` clean (216 files) · `flutter analyze` clean | ✅ |

> **What Phase 9 does not do:**
>
> - **No expense-category CRUD.** `GET /expense-categories` is read-only and
>   the six rows are seeded — a category is configuration an operator adds,
>   not a module of its own.
> - **No PDF capture from the app.** The camera files a JPEG; a PDF receipt
>   can be uploaded and viewed, but nothing in the app produces one.
> - **No currency conversion.** `currency` is validated against the codes
>   the operator configured — see the currency configuration pass below —
>   and nothing is ever re-priced from one code into another.
> - **No audit logging of expense decisions.** The service layer is
>   audit-ready — every decision is already a method call — but writes no
>   audit rows yet.
> - **No FCM notifications** on submission, approval or rejection.
> - Employee documents, onboarding, training and assets remain Phase 10's
>   scope, as they were before.

### ✅ Phase 9 · Expense currency configuration (COMPLETE)

A follow-up pass on Phase 9. The claim form was prefilling a currency that
somebody had typed into the source — a configuration value wearing a code
costume. The company's currency is now a setting the server owns and the
app reads.

| Deliverable | Status |
|---|---|
| **`system.currency` is the company/base currency** — reseeded from `INR` to **`AED`** for the UAE build, and read by everything that prints a figure: payroll, salary slips, certificates, loans, and the default on a new claim. One row, not a constant in four services | ✅ |
| **`system.supported_currencies`** (json) seeded **`["AED"]`** — the codes a claim may be filed in. One entry means there is no choice to offer; two or more turn the field into a menu; an empty list switches the membership rule off rather than refusing every claim for want of a configuration | ✅ |
| **`GET /api/v1/client-settings`** — the smallest read-only configuration window: `default_currency` + `supported_currencies`, built from an **allow-list** (a denylist is a list of things somebody remembered), behind `auth:sanctum` and no `permission:` because a non-secret value must not need a grant. It reconciles the two settings, so the default it offers is always one the API will accept | ✅ |
| **The API stays authoritative** — `StoreExpenseRequest` normalises `currency` to upper case *before* any rule runs, then refuses a code outside the configured list; `UpdateExpenseRequest` additionally accepts the code the claim was already filed in, so narrowing the setting later never locks a person out of correcting their own draft | ✅ |
| **Flutter reads it instead of guessing** — `core/config/client_settings.dart` (`ClientSettings`, `ClientSettingsSource`, `clientSettingsProvider`) fetches the configuration for a *new* claim. One configured currency is shown and closed; several become selectable; a failed fetch opens the box rather than blocking the form, because the server decides anyway. **Opening a draft never fetches it** — an edit keeps the currency the claim was filed in, and nothing is converted anywhere | ✅ |
| Tests: backend **509 passed (3288 assertions)** — +8 `ClientSettingsTest`, +2 `ExpenseTest` (`currency` rejected when unsupported, preserved when supported) · Flutter **465 passed** (+11: 7 `client_settings_test` + 4 form) · `dart format .` clean (218 files) · `flutter analyze` clean · `vendor\bin\pint --test` **PASS** (377 files) | ✅ |

> **Consequences worth knowing:** `system.currency` is shared by design, so
> payroll documents and `GET /payroll/summary` now report **AED**. Two
> assertions changed with it (`PayrollTest`, `SalaryDocumentTest`) — the
> expected value, not the behaviour. No shipped Phase 1–8 or Phase 9 row
> above was rewritten to say so.

### ✅ Phase 10 — Employee documents & onboarding (COMPLETE)

The employment file, and the state of a new joiner's. Every kind of document
is a **row rather than a rule**: `document_types` says whether a passport
needs a number, an issue date and an expiry, and which warning window it
counts down from, so nothing in the services knows what a passport is. Files
are **PDF/JPEG/PNG/WebP in private storage under a random name** — validated on
MIME, extension, byte content and size, sanitised through the same
`SelfieSanitizer` the attendance slice uses — and the API never returns a
path, only an id-based read route.

**Backend**

| Deliverable | Status |
|---|---|
| **5 migrations · 49 total · 56 tables · all `Ran`** — `document_types`, `employee_documents`, `onboarding_requirements`, `employee_onboarding`, `employee_bank_accounts` | ✅ |
| **9 seeded document types** (`DocumentTypeSeeder`): `PASSPORT`, `EMIRATES_ID`, `VISA`, `EMPLOYMENT_CONTRACT`, `LABOUR_DOCUMENTS`, `CERTIFICATE`, `MEDICAL_DOCUMENT`, `TRAINING_CERTIFICATE`, `OTHER` — the three travel documents require number + issue + expiry, everything else asks for nothing it was not told to | ✅ |
| **8 seeded onboarding requirements** (`OnboardingRequirementSeeder`) — personal information, passport, Emirates ID, visa, employment contract, bank information, employee photo, certificates — each of kind `document` (matched to a type **by code**), `data` (a comma list of non-empty employee columns) or `bank`. No boolean columns on `employee` | ✅ |
| `employee_documents.status` is stored (`pending`, `valid`, `expired`, `rejected`, `archived`); **`expiry_state` and `days_until_expiry` are computed on the server** from the type's own warning days (or `hrms.expiry.default_warning_days`) and shipped with every row. Uploads always arrive `pending`; changing the file or any identifying field resets it and clears the sign-off; verifying an expired document answers `expired`; a second decision is **409 naming the state** | ✅ |
| `EmployeeDocumentStore` — private `local` disk, `{employee-documents}/{employeeId}/{uuid}.{ext}`, `%PDF-` sniffed, image metadata stripped by `SelfieSanitizer`, download names reduced to `[A-Za-z0-9 _-]`, served as a `StreamedResponse` through `GET /employee-documents/{document}/file`. **No raw path exists in any payload**, and delete/archive never removes a file or a row from history | ✅ |
| `DocumentExpiryService` is authoritative for `valid` / `expiring_soon` / `expired`; `ScanDocumentExpiries` runs on the Scheduler (`hrms.expiry.scan_hour:6`, `scan_minute:15`) and is **idempotent through `expiry_notified_at`** — a second run in the same window changes nothing and emits no duplicate event. Two events (`DocumentExpiring`, `DocumentExpired`) are raised as the notification hooks; **no FCM is sent** | ✅ |
| `OnboardingService` — `draft → pending_documents → hr_review → completed`, `materialise()` creating the record on first read, the checklist computed from the requirements (any unexpired valid document of the type satisfies it; otherwise the newest non-archived row decides `pending_verification` / `rejected` / `expired` / `missing`), and **completion refused with 409 naming what is outstanding** rather than 403 | ✅ |
| Bank details live in **`employee_bank_accounts`** behind their own routes `GET|PUT /employees/{employee}/bank-account` with **no `permission:` middleware** — the row-level policy decides — are **encrypted at rest**, and are **absent from `EmployeeResource` and from every generic list**. `APP_KEY` must not be rotated casually (see `docs/SECURITY.md` §4) | ✅ |
| Permissions: **7 new → 80 total, 401 grants** — `documents.{create,update,verify,delete,expiry.view,manage}` and the new `onboarding.{view,manage}`; `documents.view` already existed. Managers deliberately do **not** receive `documents.manage`, so supervising people does not open a colleague's passport | ✅ |
| Routes: **16 new — 170 definitions / 175 registered** — `employee-documents` (+ `/expiring`, `/{document}/verify`, `/{document}/reject`, `/{document}/file`, `/{document}`), `document-types`, `onboarding` (+ `/{employee}`, `/{employee}/complete`) and the two bank-account routes; `/employee-documents/expiring` is declared **before** `/{document}` (the Phase 7 ordering rule) | ✅ |
| Policies: `EmployeeDocumentPolicy`, `DocumentTypePolicy`, `EmployeeOnboardingPolicy`, plus `EmployeePolicy::viewBankAccount` / `updateBankAccount` — row scope in `Visibility::{mayViewOthersDocuments, employeeDocumentsFor, employeeDocumentIsVisible, onboardingEmployeesFor, onboardingIsVisible, mayFileDocumentsFor}` | ✅ |
| Tests: backend **all green on `hrms_testing`** — `EmployeeDocumentTest`, `DocumentExpiryScanTest`, `OnboardingTest`, `EmployeeBankAccountTest` · `composer validate` valid · `vendor\bin\pint --test` **PASS** (419 files) | ✅ |

**Flutter**

| Deliverable | Status |
|---|---|
| `mobile/lib/features/documents/` — `domain/` (`employee_document`, `document_type`, `document_repository`), `data/` (`api_document_repository`, `document_source`), `presentation/` (list, expiry report, form, detail, source sheet, controller) | ✅ |
| `mobile/lib/features/onboarding/` — `domain/` (`onboarding`, `onboarding_repository`), `data/api_onboarding_repository`, `presentation/` (list, detail, controller) | ✅ |
| **Expiry is always words as well as colour** — "Expires in 5 days", "Expired 12 days ago", "Expires today", "Expires tomorrow", "No expiry date", each with an icon; the server's `expiry_state` and `days_until_expiry` are rendered and never re-derived here | ✅ |
| One `DocumentSourceSheet` offers all three doors: **camera** reuses `documentCameraProvider` + `CameraCaptureSheet`, gallery and PDF go through the new `DocumentFilePicker` interface backed by **`file_picker`** — one added package, no duplicate picker, bytes going straight to the request with no temporary file and no path in any payload | ✅ |
| 7 new routes — `/documents`, `/documents/new`, `/documents/expiring`, `/documents/:id`, `/documents/:id/edit`, `/onboarding`, `/onboarding/:id` → **65 `GoRoute` entries**; `/documents/new` and `/documents/expiring` are declared **before** `/documents/:id` | ✅ |
| Two Home doors — **Documents** (`Icons.folder_shared_outlined`, "Passports, IDs, visas and contracts", `documents.view`) and **Onboarding** (`Icons.person_add_alt_outlined`, "Where new joiners stand", `onboarding.view`) — each behind its own grant, never behind a wildcard `*.view` | ✅ |
| `PermissionScope` gained 10 getters; `ApiClient.putMultipart()` added beside `postMultipart()` | ✅ |
| Tests: **86 new Phase 10 tests** across `test/features/documents/`, `test/features/onboarding/`, `test/features/home/home_phase10_test.dart` and `test/app_phase10_routes_test.dart` · `dart format .` clean (246 files) · `flutter analyze` clean · full `flutter test` green | ✅ |

> **What Phase 10 does not do:**
>
> - **No training, no assets.** Both were named in the original Phase 10
>   sketch and are **out of this phase's approved scope**; they are not
>   started and not scheduled.
> - **No FCM.** The scan raises events and writes `expiry_notified_at`;
>   nothing is pushed to a device.
> - **No audit logging.** Every document and onboarding mutation already
>   runs through a service method, which is where that log will attach.
> - **No physical deletion.** Archiving removes a row from the active list
>   and leaves the record and its file on the employment file — a delete
>   endpoint exists only for a document that was never verified.
> - **No bank details in any generic API.** The bank account is its own
>   route, its own policy check and its own encrypted column; it is never in
>   `EmployeeResource`, and it is never logged.

### ✅ Phase 11 — Training & asset management (COMPLETE)

Two registers, both configured rather than coded. What a course *is* and what
kind of thing a laptop *is* are **rows an operator may add, rename and
retire** — `training_types` and `asset_types` — so nothing in a service, a
screen or a test knows that `WORKING_AT_HEIGHTS` exists. Enrolment is a
ledger with a unique key rather than a list of names, and a hand-over is an
**append-only history row**: the register's `status` is a projection of the
open assignment, never a second opinion about it.

**Backend**

| Deliverable | Status |
|---|---|
| **6 migrations · 55 total · 62 tables · all `Ran`** — `training_types`, `training_programs`, `employee_trainings`, `asset_types`, `assets`, `asset_assignments` | ✅ |
| **2 reference seeders, not fixtures** — `TrainingTypeSeeder` (8 codes) and `AssetTypeSeeder` (7 codes). **Programs are deliberately not seeded**: a catalogue is an operator's to write | ✅ |
| `training_programs.certificate_validity_days` is **days only**, `NULL` meaning *never lapses* — `0` would mean "lapses the instant it is issued", a different claim one character away | ✅ |
| `employee_trainings` duplicate guard: two checks before the insert (same person + same course + same date → **409** `'That person is already enrolled on this course for that date.'`, then same person + same open course → **409** `'That person already has a place on this course.'`). Recertification is a **new row**, never a rewrite of the finished one | ✅ |
| Certificate expiry states `none / valid / expiring_soon / expired` are all **computed** by `EmployeeTraining::certificateExpiryState()` from the certificate's own dates against `hrms.expiry.default_warning_days` (30, `HRMS_DOCUMENT_WARNING_DAYS`) — nothing stores a state that can drift | ✅ |
| `ScansExpiringRows` trait + `TrainingExpiryService`; job `ScanTrainingExpiries` **daily 06:20**, `withoutOverlapping()` + `onOneServer()`, `ShouldBeUnique`, `$tries = 1`; raises `EmployeeTrainingExpiring` / `EmployeeTrainingExpired` once per record per window through `expiry_notified_at`, and **sends no FCM** | ✅ |
| `EmployeeTraining.status` (`enrolled, scheduled, in_progress, completed, failed, cancelled, expired`) with `isOpen()` / `isTerminal()` / `isCompletable` derived on the model, so the API and the screen cannot disagree about which states may still move | ✅ |
| Certificates are uploaded through the **existing private-file pipeline** (`EmployeeDocumentStore`, disk `local`) and served by `GET /employee-training/{id}/file` — a second storage rule was not invented for one more PDF | ✅ |
| `assets.status` (`available, assigned, maintenance, damaged, lost, retired`) is **separate from `current_condition`** (`new, good, fair, poor`); an asset is very often both `assigned` and `fair` | ✅ |
| `AssetService::assign()` guards the "one open hand-over per asset" rule inside a transaction with `lockForUpdate`; `returnAsset()` sets `current_condition = returned_condition` and moves to `maintenance` when poor, else `available`. A return's `remarks` **replace** the hand-over's; a return with none leaves it standing | ✅ |
| `AssetService::changeStatus()` checks the open-assignment rule **before** `Asset::TRANSITIONS`, so `assigned` is never offered as a status and a held asset cannot be retired out from under its holder | ✅ |
| `purchase_cost DECIMAL(12,2)` is **omitted, not nulled**, from `AssetResource` unless the reader holds `assets.manage` — an absent key and a free asset are two different statements | ✅ |
| Service-routed mutations everywhere (`TrainingService`, `TrainingExpiryService`, `AssetService`) so the future audit log attaches to one place per act | ✅ |
| Permissions: **15 new → 95 total, 462 grants** — the eight `training.*` and seven `assets.*` verbs. **4 new policies → 30 total** — `EmployeeTrainingPolicy`, `TrainingProgramPolicy`, `AssetPolicy`, `AssetAssignmentPolicy`; `TrainingType` / `AssetType` are read-only and gated on the route with `abort_unless` | ✅ |
| Routes: **24 new — 194 definitions / 199 registered** — `training-types`, `training-programs` (+ `/training-compliance`, `/expiring`), `employee-training` (+ `/expiring`, `/{row}/complete`, `/{row}/cancel`, `/{row}/file`), `asset-types`, `assets` (+ `/{asset}/assign`, `/{asset}/return`, `/{asset}/status`) and `asset-assignments`; `/training-programs/expiring` and `/training-compliance` are declared **before** `/{program}` (the Phase 7 ordering rule) | ✅ |
| 11 FormRequests; certificate `file` rules reuse `ValidatesEmployeeDocuments`; every payload `prohibits()` the server-owned fields | ✅ |
| Tests: backend **609 passed (4103 assertions)** on `hrms_testing` — 56 new (`TrainingManagementTest`, `TrainingComplianceTest`, `TrainingExpiryScanTest`, `AssetManagementTest`, `AssetAssignmentHistoryTest`) on the shared `BuildsTrainingAssets` scaffold · `composer validate` valid · `vendor\bin\pint --test` **PASS** (474 files) · `migrate:status` **55 Ran / 0 pending** | ✅ |

**Flutter**

| Deliverable | Status |
|---|---|
| `mobile/lib/features/training/` — **15 files**, three layers: `training_type` · `training_program` · `employee_training` · `training_repository` · `api_training_repository` · `training_controller` + nine screens (list, detail with completion/cancel, enrol, catalogue, program form, expiry report, compliance, filters) | ✅ |
| `mobile/lib/features/assets/` — **11 files**: `asset_type` · `asset` · `asset_assignment` · `asset_repository` · `api_asset_repository` · `asset_controller` + list, detail, form, hand-over log and the three hand-over sheets | ✅ |
| **Status and condition are two chips, in words, never one colour** — an asset can be `assigned` and `poor` at once, and a tint would be a second signal pretending to be the first | ✅ |
| **"We did not ask" is never drawn as "it is free"** — a payload with no `assignments` says *Holders not loaded*; one that was asked and answered empty says *Nobody holds it* | ✅ |
| **Cost is shown only when it was sent** — `Asset.costVisible` reads `containsKey('purchase_cost')`, and the row reads *Not shown to your role* rather than *Not recorded* | ✅ |
| Expiry is words + icon everywhere: `Expires in 5 days`, `Expired 12 days ago`, `Never expires`, `No card issued` — the server's `expiry_state` and `days_until_expiry` are rendered, never re-derived | ✅ |
| 13 new routes — `/training`, `/training/new`, `/training/expiring`, `/training/compliance`, `/training/programs` (+ `/new`, `/:id`), `/training/:id`, `/assets`, `/assets/new`, `/assets/history`, `/assets/:id`, `/assets/:id/edit` → **78 `GoRoute` entries**; `/training/programs` is declared **before** `/training/:id`, and `/assets/history` **before** `/assets/:id` | ✅ |
| Two Home doors — **Training** (`Icons.school_outlined`, `/training`) and **Assets** (`Icons.inventory_2_outlined`, `/assets`) — each behind its own grant; the expiry report and the hand-over log are *not* doors of their own | ✅ |
| `PermissionScope` gained 13 `can*` getters; `ApiClient.patch()` added (one caller: `PATCH /assets/{asset}/status`) | ✅ |
| Harness `test/support/phase11.dart` — `ScriptedTraining` · `ScriptedAssets` · `scopedPhase11(...)` · `phase11Router(...)` · JSON payload builders for both trees, parsed by `rows()` so a transition merges into the payload | ✅ |
| Tests: **654 passed** (551 before Phase 11, **+103** across 12 new files) · `dart format .` **clean (285 files)** · `flutter analyze` **clean** · full `flutter test` green | ✅ |

> **What Phase 11 does not do:**
>
> - **No FCM.** The expiry job raises two events and writes
>   `expiry_notified_at`; nothing is pushed to a device.
> - **No audit logging.** Every mutation already runs through a service
>   method, which is where that log will attach.
> - **No training-type or asset-type CRUD screens.** The rows exist, are
>   seeded and are read by both sides; writing them is configuration work,
>   not a module.
> - **No certificate verification endpoint.** The file is private, served by
>   its id-based route, and nothing yet checks a card against its issuer.
> - **No `POST /asset-assignments`.** A hand-over is created only by
>   `POST /assets/{asset}/assign`, so there is no second door into the table.

### Planned Phases

| Phase | Scope | Status |
|---|---|---|
| **1b** | Flutter toolchain + Flutter project skeleton | ✅ Done |
| **2** | Core database schema + seeders (roles, permissions, settings) | ✅ Done |
| **3** | Authentication (Sanctum) + Flutter auth feature | ✅ Done |
| **4** | Employees, Projects, Sites, Assignments (first vertical slice) | ✅ Done |
| **5** | Attendance: geofence, selfie, check-in/out, site visits, movement timeline, offline queue | ✅ Done |
| **6** | Leave: types, balances, requests, approval workflow, sick-cert → LOP, holiday calendar, timesheets, overtime | ✅ Done |
| **7** | Site activity reports & daily site reports (PDF) | ✅ Done |
| **8** | Payroll, salary slips, certificates, loans | ✅ Done |
| **8h** | Payroll financial-safety hardening — net-salary floor, partial/carry-forward repayments | ✅ Done |
| **9** | Expenses: workflow approval + private receipts | ✅ Done |
| **10** | Employee documents (types, private storage, expiry) + onboarding | ✅ Done |
| **11** | Training management (programs, enrolment, certificates, expiry, compliance) + asset management (register, hand-over, return, history) | ✅ Done |
| **12** | FCM notifications, full audit logging, advanced dashboards, final reporting & exports | ⬜ |
| **13** | Testing, security audit, deployment hardening, backups | ⬜ |

> **Shifts (API + screens) and background/automatic attendance sync are
> unscheduled.** The `shifts` table (Phase 2) and the shift resolution used
> at check-in already exist; what is missing is the CRUD and the automatic
> sync, and neither has a phase number yet.

---

## 9. Security Notes

**Never commit:**

```
.env
*.jks / *.keystore / *.pem
google-services.json
GoogleService-Info.plist
*firebase-adminsdk*.json
database passwords / API keys / private certificates
```

All of the above are blocked by the root `.gitignore`. See [`docs/SECURITY.md`](docs/SECURITY.md).

**Never commit payroll output either.** No `.pdf` salary slip, no salary
certificate, no exported list of who earns what. The app renders both
documents on demand behind an authenticated route and stores nothing — there
is no file in this repository to leak, and a run report belongs in the app,
not in a screenshot pasted into a ticket. `net_salary` never appears in an
employee resource, in `/payroll/summary` (counts and totals, no names) or in
a log line. See [`docs/SECURITY.md`](docs/SECURITY.md) §4.7 and §11.

---

## 10. License

Private / proprietary. Not published.
