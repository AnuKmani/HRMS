# Architecture

> **Status:** Phase 3 — backend authentication and the Flutter auth foundation are
> implemented and documented as built. Sections marked ⬜ are planned but not yet
> built.

---

## 1. System Overview

```
┌────────────────────────┐
│    Flutter Mobile App  │   Riverpod → Repository → Dio
│   (Android first)      │   Offline queue → Drift/SQLite
└───────────┬────────────┘
            │  HTTPS  (token: Sanctum)
┌───────────▼────────────┐
│   Laravel REST API     │   Route → Controller → FormRequest
│      /api/v1/          │            ↓
│                        │        Service        ← business rules live HERE
│                        │            ↓
│                        │         Policy        ← authorization enforced HERE
│                        │            ↓
│                        │      API Resource     ← response shape
└───────────┬────────────┘
            │
┌───────────▼────────────┐
│     MariaDB 10.4.28    │
└───────────┬────────────┘
            │
┌───────────▼────────────┐   ┌──────────────┐   ┌─────────────┐
│  Private File Storage  │   │ Queue Worker │   │  Scheduler  │
│ (selfies, IDs, visas)  │   │   + FCM      │   │ (cron jobs) │
└────────────────────────┘   └──────────────┘   └─────────────┘
```

---

## 2. Guiding Principles

### 2.1 The server is always the authority

The mobile app is a *client*, never a *trust source*.

| Concern | Flutter may | Flutter must NOT |
|---|---|---|
| Geofence | Calculate and **display** distance for UX | Decide whether check-in is allowed |
| Leave balance | Display remaining days | Compute or decrement the balance |
| Overtime | Show requested hours | Approve overtime |
| Payroll | Render the slip | Calculate net salary |
| Permissions | Hide UI the user cannot use | Enforce access control |

Every rule in §2.1 is enforced by a Laravel **Service** or **Policy**.

### 2.2 Business rules are configurable, not hard-coded

Stored in a `settings` table and in entity configuration — working hours, grace period,
overtime threshold, geofence radius, sick-certificate deadline, leave entitlements,
carry-forward limits, approval workflows, notification timing.

### 2.3 Append-only where history matters

Site assignments, attendance overrides, approvals and payroll runs are **never overwritten**.
A new record is written with dates and an actor so the history stays auditable.

### 2.4 Design for poor connectivity

Construction sites lose signal. Attendance must queue locally, sync safely, and never
create duplicates.

---

## 3. Backend Architecture (Laravel)

### 3.1 Request lifecycle

```
HTTP Request
   ↓
routes/api.php                    route definition + middleware (auth:sanctum, ability)
   ↓
FormRequest                       validation (422 returned automatically on failure)
   ↓
Controller                        thin — coordinates only, no business logic
   ↓
Service                           business rules (geofence, leave balance, payroll)
   ↓
Policy / Gate                     authorization (403 if denied)
   ↓
Model (Eloquent)                  persistence
   ↓
API Resource                      uniform response envelope
   ↓
JSON Response
```

### 3.2 Layer responsibilities

| Layer | Responsibility | Must NOT contain |
|---|---|---|
| **Controller** | Read request, call service, return resource | Business rules, queries |
| **FormRequest** | Validate input shape and rules | Business logic |
| **Service** | Domain rules, transactions, orchestration | HTTP/Request objects |
| **Policy** | Resource-level authorization | Data mutation |
| **Resource** | Response shape | Logic |
| **Model** | Table mapping, relations, scopes | Cross-entity rules |

### 3.3 Why Services exist

Geofence validation, leave balance calculation, LOP conversion and payroll calculation are
each several steps long and must be unit-testable **without HTTP**. Putting them in a
Controller makes them untestable and un-reusable from a Job or a Scheduler command.

### 3.4 Standard response envelope

**Success**
```json
{
    "success": true,
    "message": "Attendance checked in successfully.",
    "data": {}
}
```

**Validation failure (422)**
```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "site_id": ["The site id field is required."]
    }
}
```

**Error (401 / 403 / 404 / 429 / 500)**
```json
{
    "success": false,
    "message": "Unauthenticated.",
    "errors": {}
}
```

`App\Http\Responses\ApiResponse` is the only place this shape is built — no
controller, exception renderer or rate limiter formats JSON by hand. Two
invariants the client relies on:

- `data` is **always a JSON object**, never `[]`. When there is nothing to
  report it is emitted from `(object) []`, so Flutter can treat it as a map
  without a null or type check.
- `errors` is **always a JSON object**. It is `{}` unless the failure is
  per-field, in which case it is Laravel's `field: [messages]` map.

**Implemented in Phase 3 ✅**

The validation example above is what Laravel's `ValidationException` produces;
`errors` values are arrays of messages, which `apiExceptionFrom()` in
`mobile/lib/core/network/api_exception.dart` flattens to the first message per
field.

### 3.5 Authentication

- **Laravel Sanctum** personal access tokens
- One token per device — individually revocable
- Token abilities used for coarse scoping
- Logout revokes the current token
- Never log tokens or passwords

**Implemented in Phase 3 ✅**

| Property | How | Why |
|---|---|---|
| Stateless | `Hash::check()` against the stored hash, not `Auth::attempt()` | `attempt()` logs the user into the *web* guard and writes a session row — meaningless for a token API |
| Enumeration-proof | One identical `401` for unknown address and wrong password | A `422` here would confirm which addresses are registered |
| Deactivated account | Checked **after** the password verifies, `401` with an explicit message | Checking first would let anyone discover which accounts are deactivated |
| One token per device | `device_name` from the client; login deletes an existing token with that name before creating the next | Signing in twice on the same handset replaces its session rather than orphaning a row nobody can revoke |
| Token returned once | Plain token appears only in the login response | Sanctum stores a SHA-256 hash, so it cannot be recovered later |
| Session restore | `GET /auth/me` on launch; `401` discards the token, anything else keeps it | An unreachable network must not look like being signed out |

Endpoints: `POST /auth/login|logout|change-password|forgot-password|reset-password`,
`GET /auth/me|auth/sessions`, `DELETE /auth/sessions/{id}`, `GET /roles`.
See [`API_DOCUMENTATION.md`](API_DOCUMENTATION.md) §2.1.

> `forgot-password` / `reset-password` are built and validated but answer
> **501** until a real mailer is configured (`PASSWORD_RESET_ENABLED=false` by
> default). `MAIL_MAILER=log` cannot actually deliver a link, and returning a
> success the user could never act on would be a lie. See
> [`SECURITY.md`](SECURITY.md) §2.

### 3.6 Authorization

Two complementary layers:

1. **`spatie/laravel-permission`** — roles and granular permissions
   (`employees.view`, `leave.approve`, `payroll.manage`, …)
2. **Policies** — per-resource rules (an employee may read *their own* attendance,
   but not a colleague's)

Route middleware checks the permission; the Policy checks the ownership/scope.

**Implemented in Phase 2 ✅**

- Package: `spatie/laravel-permission` **^6.25** (v6 is the line that supports
  PHP `^8.2` + Laravel 12; v7/v8 require PHP 8.3+ and will not install here).
- Aliases registered in `bootstrap/app.php`, because spatie does not register them
  itself on Laravel 11+:

  ```php
  $middleware->alias([
      'role'                => RoleMiddleware::class,
      'permission'          => PermissionMiddleware::class,
      'role_or_permission'  => RoleOrPermissionMiddleware::class,
  ]);
  ```

- Usage — **always enforced server-side, never trusted from Flutter**:

  ```php
  Route::middleware(['auth:sanctum', 'permission:payroll.manage'])
      ->get('/payroll/runs', [PayrollController::class, 'index']);

  Route::middleware(['role:HR Admin|Super Admin'])
      ->post('/employees', [EmployeeController::class, 'store']);
  ```

- Or per-method / in code: `$user->can('employees.view')`,
  `$this->authorize('update', $employee)`.

#### The two layers answer different questions

| Layer | Question it answers | Example |
|---|---|---|
| **Permission** (middleware) | *May this kind of user touch this module at all?* | Does this role have `attendance.manage`? |
| **Policy** (Eloquent) | *May this specific user touch this specific row?* | Is it **their own** attendance record? |

A permission is therefore a **coarse gate**, not a data filter. The `Employee` role
holds `attendance.view` so it can open the attendance screen at all — row scoping to
their own records is the Policy's job (Phase 4). Never use a permission alone to
decide *which* rows to return.

#### Roles and permissions

| | |
|---|---|
| Roles | 10 — Super Admin, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Engineer, Site Supervisor, Finance, Management, Employee |
| Permissions | 39, all named `resource.action` (lowercase) |
| Grants | 163 rows in `role_has_permissions` |
| Seeders | `RoleSeeder` → `PermissionSeeder` → `RolePermissionSeeder` (order matters) |

Permission catalogue lives in one place — `PermissionSeeder::PERMISSIONS`, grouped by
module. Adding a module means adding a line there; nothing else needs the full list.

```
dashboard    dashboard.view
employees    employees.view | .create | .update | .delete
departments  departments.view | .manage
designations designations.view | .manage
attendance   attendance.view | .manage
leave        leave.view | .request | .approve | .manage
payroll      payroll.view | .manage
projects     projects.view | .manage
sites        sites.view | .manage
shifts       shifts.view | .manage
assignments  assignments.view | .manage
reports      reports.view | .export
documents    documents.view | .manage
expenses     expenses.view | .approve | .manage
settings     settings.view | .manage
roles        roles.view | .manage
users        users.view | .manage
audit        audit.view
```

**Super Admin** holds `['*']` — every permission, resolved from the catalogue at seed
time rather than hard-coded, so a newly added permission is granted automatically.
Every other role is an explicit allow-list: anything absent is **denied**. The mapping
is `RolePermissionSeeder::MAP`, and `RbacTest` asserts both directions (Super Admin has
all 39; `Employee` is denied `payroll.manage`, `employees.delete`, `attendance.manage`,
`leave.approve`, `audit.view`).

---

## 4. Frontend Architecture (Flutter)

### 4.1 Layer flow

```
Screen (Widget)
   ↓  watches
Provider / Controller (Riverpod)      ← state, async orchestration
   ↓  calls
Repository                            ← decides: network or local DB?
   ↓
ApiService (Dio)  ──────────────┐
   ↓                           │
Local DB (Drift / SQLite)  ← offline queue
   ↓
Laravel REST API
```

### 4.2 What each piece is and why it exists

**Screen / Widget** — what the user sees. Contains no HTTP calls and no business rules.
It *watches* a provider and rebuilds when state changes.

**Provider (Riverpod)** — holds the state of a feature (e.g. `attendanceProvider`).
It is testable without a `BuildContext`, which is why Riverpod was chosen over the
older `Provider` package.

**Repository** — the single class a provider talks to. Its one job is to answer
*"where does this data come from?"*. Because of this, adding offline support means
changing the Repository — not the screen and not the provider.

**ApiService (Dio)** — the HTTP client. Interceptors live here, giving one central place for:
attaching the token, handling `401` (session expired), mapping `422` field errors,
retries, timeouts and logging.

**Local DB (Drift/SQLite)** — offline storage. Attendance data is *relational*
(attendance ↔ site ↔ project ↔ employee ↔ sync status), needs transactions, and needs a
unique constraint for duplicate prevention — hence a real database rather than key-value
storage.

### 4.3 Feature-first folder layout

```
lib/
├── core/
│   ├── config/        # app config, environment, feature flags
│   ├── constants/     # API routes, storage keys, regex, margins
│   ├── errors/        # Failure types, exception mapping
│   ├── network/       # Dio client, interceptors, API result wrapper
│   ├── storage/       # Secure storage, local DB, shared prefs
│   ├── utils/         # date, distance, validators, formatters
│   └── widgets/       # shared UI (buttons, loaders, empty/error states)
│
├── features/
│   ├── auth/          # login, forgot password, session
│   ├── dashboard/
│   ├── employees/
│   ├── attendance/    # check-in, check-out, selfie, geofence
│   ├── site_visits/
│   ├── site_reports/
│   ├── projects/
│   ├── sites/
│   ├── leave/
│   ├── timesheet/
│   ├── overtime/
│   ├── payroll/
│   ├── documents/
│   ├── expenses/
│   ├── loans/
│   ├── training/
│   ├── assets/
│   └── notifications/
│
└── main.dart
```

**What Phase 3 actually created** — `core/router/` is an addition to the list
above (GoRouter lives with the rest of the cross-cutting plumbing), and the
auth feature is deliberately flat:

```
mobile/lib/
├── core/
│   ├── config/app_config.dart        # --dart-define base URL
│   ├── network/api_client.dart       # Dio, bearer interceptor, session-rejected stream
│   ├── network/api_exception.dart    # envelope → ApiException
│   ├── router/app_router.dart        # GoRouter + refreshListenable guard
│   ├── storage/token_store.dart      # flutter_secure_storage
│   └── storage/device_identity.dart  # stable per-install device name
├── features/
│   ├── auth/
│   │   ├── auth_models.dart          # AuthUser, EmployeeBrief, LoginResult
│   │   ├── auth_repository.dart      # AuthRepository + ApiAuthRepository
│   │   ├── auth_state.dart           # AuthStatus + AuthState
│   │   ├── auth_controller.dart      # Notifier<AuthState>
│   │   ├── login_screen.dart
│   │   └── splash_screen.dart
│   └── home/home_screen.dart         # Phase 4 landing spot
└── main.dart
```

Five files do not justify four directories. `data/`, `providers/` and
`presentation/` appear once a feature grows local sources — the offline queue
in Phase 6 is the point where `attendance/` should adopt the full shape below,
and `auth/` should follow when forgot-password UI lands.

Each feature folder follows the same internal shape:

```
features/attendance/
├── data/
│   ├── models/        # Attendance, AttendanceCheckInRequest
│   ├── repositories/  # AttendanceRepository
│   └── sources/       # AttendanceApi, AttendanceLocalDb
├── providers/         # Riverpod providers
├── presentation/      # screens + widgets
└── application/       # use-cases / orchestration (when complex)
```

**Why feature-first rather than layer-first?** Attendance and payroll change for
different reasons and at different speeds. Isolating them means a change to payroll
cannot break attendance.

---

## 5. Key Design Decisions

### 5.1 One attendance row per employee per work day

`attendances` holds a single row per employee per date, carrying check-in *and*
check-out columns plus both site references (check-out may happen at a different site).
Intra-day movement is modelled separately in `site_visits`.

*Alternative considered:* one row per punch. Rejected — computing daily working hours
and detecting missing check-outs becomes significantly harder.

### 5.2 Site assignments are append-only

`employee_site_assignments` carries `start_date`, `end_date`, `status` and `created_by`.
The *current* site is resolved by finding the open-dated row; previous assignments are
never overwritten. This directly satisfies the requirement to maintain assignment history.

**Implemented in Phase 2 ✅** — schema enforces it, not just convention:

- all three FKs are `RESTRICT`, so hard-deleting an employee, project or site that has
  assignment history throws rather than erasing it;
- the table has **no `deleted_at`** — it is history, not master data;
- `EmployeeSiteAssignment::end()` closes a row in place (`status='ended'`,
  `end_date=today`) instead of deleting it;
- `Employee::currentSiteAssignment` is a `hasOne(...)->latestOfMany('start_date')`
  scoped to `status='active'`.

`AssignmentHistoryTest::moving_site_appends_history_instead_of_overwriting` moves an
employee from Site A to Site B and asserts **both** rows survive with distinct
`site_id`s and correct statuses.

### 5.3 A `settings` table is mandatory

Working hours, grace period, overtime threshold, geofence radius, sick-certificate
deadline, leave rules and approval workflows must all be changeable by an authorized
user without a code deploy.

**Implemented in Phase 2 ✅** — table `settings`, model `App\Models\Setting`,
accessor `App\Services\SettingsService`.

Business rules are **read, never hard-coded**. Inject the service and ask for the key:

```php
public function __construct(private readonly SettingsService $settings) {}

$grace = $this->settings->minutes('attendance.grace_period_minutes', 10);
$days  = $this->settings->int('leave.sick_certificate_deadline_days', 3);
$hours = $this->settings->json('working_hours.default');
```

The defaults in the second argument are a **fallback for a missing row**, not the
source of truth — `SettingSeeder` owns the shipped values.

| Property | Design |
|---|---|
| `key` | Dotted namespace, `UNIQUE` — `attendance.grace_period_minutes` |
| `type` | `string \| integer \| boolean \| decimal \| json \| date \| time` — `Setting::castValue()` returns the right PHP type, so no caller casts |
| `group` | `attendance`, `leave`, `notification`, `working_hours`, `system` — renders an admin screen without bespoke grouping code |
| `is_editable` | Distinguishes operator-tunable rules from system-owned values |
| Caching | `Cache::rememberForever`, flushed by `saved`/`deleted` model events |

Seeded rules (12 rows): grace period, overtime threshold, default geofence radius,
late-arrival escalation, sick-certificate deadline, certificate-required threshold,
notification reminder offset, daily digest time, document expiry warning, default
working hours (JSON), date format, currency.

> **Rule — all settings writes go through the model.** Cache invalidation hangs off
> Eloquent `saved`/`deleted` events registered in `AppServiceProvider`, so every write
> **must** use the `Setting` model: `$setting->update(...)`, `Setting::create(...)`,
> `Setting::updateOrCreate(...)`. A raw `DB::table('settings')->update(...)` or
> `Setting::query()->update(...)` (mass update on an unhydrated model) bypasses those
> events and leaves a stale cache until something else flushes it.
>
> **Convention confirmed across the codebase** — the only write paths are:
>
> | Writer | Style | Invalidation |
> |---|---|---|
> | `SettingSeeder` | `Setting::query()->updateOrCreate(...)` | ✅ model events + explicit `refresh()` |
> | `SettingFactory` / tests | `Setting::create(...)`, `->first()->update(...)` | ✅ model events |
> | Application code | `SettingsService` (read-only) | n/a — reads never write |
>
> There is **no** `DB::table('settings')` call anywhere in `app/`, `database/` or
> `tests/`. `SettingsTest` asserts the cache actually refreshes after a write, so a
> future regression to a bypassing write style fails the suite.

**How this differs from hard-coding:** a grace period of 10 minutes written in source
requires a code change, a review, a deploy and a restart on every environment. Stored
in `settings`, HR changes it once, immediately, and it is auditable and testable
against a non-default value.

### 5.4 Offline sync uses client-generated idempotency keys

Each queued action gets a UUID created on the device. The server stores it under a
**unique** constraint, so re-uploading the same batch can never create duplicate
attendance. The server still re-validates geofence and timing on every sync.

### 5.5 Private storage with signed access

Selfies, passports, Emirates IDs, visas, contracts and salary documents live outside
`public/` and are only reachable through authorized, expiring URLs.

---

## 6. Deployment Topology (cloud-ready)

```
Flutter Mobile App
       │ HTTPS
Laravel REST API      ← behind nginx/Apache, TLS terminated
       │
     MariaDB 10.4.28  ← daily backups
       │
Private File Storage  ← encrypted at rest, versioned backups

Side processes:
  • Queue worker      (notifications, PDF generation, exports)
  • Laravel Scheduler (document expiry, LOP conversion, reminders)
  • FCM               (push delivery)
  • Backup job
  • Monitoring / logging
```

Designed to run on AWS, Azure, GCP, DigitalOcean or any Linux VPS — **no cloud-vendor
lock-in**. Only standard requirements: PHP-FPM, MariaDB, a queue worker and a cron entry.

---

## 7. Non-Goals

The following are explicitly **out of scope** unless later requested:

- ❌ Continuous background GPS tracking
- ❌ Facial recognition on selfies
- ❌ Real-time location monitoring of employees
- ❌ Location collection outside of specific business actions
  (attendance, site visits, site activity reports)

---

## 8. Document History

| Date | Change |
|---|---|
| Phase 1 | Initial architecture — system overview, layering, key decisions |
| Phase 2 | RBAC implemented (spatie ^6.25, 10 roles / 39 permissions, middleware aliases); `settings` table + `SettingsService`; append-only assignments; core schema (14 migrations, 23 tables) |
