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
│   │   │   └── attendance/  #   today screen, geofence, selfie, offline queue
│   │   ├── app.dart         # MaterialApp.router
│   │   └── main.dart        # ProviderScope + runApp
│   └── test/                # fakes/, app_test.dart, feature + unit tests
│
├── backend/                 # Laravel REST API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/   # Auth, org, attendance, site visits, movement
│   │   │   ├── Requests/             # Form Requests for every write endpoint
│   │   │   ├── Resources/            # ApiResponse-backed resource classes
│   │   │   └── Responses/            # ApiResponse — the response envelope
│   │   ├── Models/          # Eloquent models
│   │   ├── Services/        # Business logic (attendance, geofence, working time…)
│   │   ├── Policies/        # Authorization — 8 policies
│   │   ├── Support/         # Visibility scoping, Geo (haversine), ClientInput
│   │   ├── Jobs/            # Queued work
│   │   └── Notifications/   # FCM + database notifications
│   ├── config/
│   │   ├── hrms.php         # visibility lists + attendance/storage config
│   │   └── rate_limiting.php   # login, password-reset, attendance limits
│   ├── database/migrations/
│   ├── routes/api.php
│   └── tests/               # Feature + Unit (308 tests)
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

**Implemented (Phase 2):** 40 permissions, all named `resource.action`, seeded by
`RoleSeeder` + `PermissionSeeder` + `RolePermissionSeeder`. Super Admin holds every
permission; each other role is an explicit allow-list, so anything absent is denied.
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
php artisan migrate --seed        # creates 23 tables + RBAC/settings seed data
php artisan serve          # http://127.0.0.1:8000
```

```bash
php artisan test                  # 120 tests, 517 assertions
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
flutter test                 # 43 tests, no device or server required
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
| 10 roles · 40 permissions · 168 role-permission grants (167 until Phase 5 gave `attendance.view` to Employee) | ✅ |
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

### Planned Phases

| Phase | Scope | Status |
|---|---|---|
| **1b** | Flutter toolchain + Flutter project skeleton | ✅ Done |
| **2** | Core database schema + seeders (roles, permissions, settings) | ✅ Done |
| **3** | Authentication (Sanctum) + Flutter auth feature | ✅ Done |
| **4** | Employees, Projects, Sites, Assignments (first vertical slice) | ✅ Done |
| **5** | Attendance: geofence, selfie, check-in/out, site visits, movement timeline, offline queue | ✅ Done |
| **6** | *(absorbed into 5 — offline sync shipped there; reserved for automatic background sync)* | ⬜ |
| **7** | Site activity reports & daily reports (PDF) | ⬜ Next |
| **8** | Shifts, timesheets, overtime | ⬜ |
| **9** | Leave, balances, sick-cert → LOP automation | ⬜ |
| **10** | Holidays, documents + expiry, onboarding, training, assets | ⬜ |
| **11** | Payroll, salary slips, certificates, loans, expenses | ⬜ |
| **12** | FCM notifications, dashboards, reports & exports | ⬜ |
| **13** | Testing, security audit, deployment, backups | ⬜ |

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

---

## 10. License

Private / proprietary. Not published.
