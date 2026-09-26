# Architecture

> **Status:** Phase 1 — Foundation. This document is written incrementally as each phase is
> implemented. Sections marked ⬜ are planned but not yet built.

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
    "errors": null
}
```

A single base `ApiResponse` resource emits this shape so it is consistent across every
endpoint.

### 3.5 Authentication

- **Laravel Sanctum** personal access tokens
- One token per device — individually revocable
- Token abilities used for coarse scoping
- Logout revokes the current token
- Never log tokens or passwords

### 3.6 Authorization

Two complementary layers:

1. **`spatie/laravel-permission`** — roles and granular permissions
   (`employees.view`, `leave.approve`, `payroll.manage`, …)
2. **Policies** — per-resource rules (an employee may read *their own* attendance,
   but not a colleague's)

Route middleware checks the permission; the Policy checks the ownership/scope.

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

### 5.3 A `settings` table is mandatory

Working hours, grace period, overtime threshold, geofence radius, sick-certificate
deadline, leave rules and approval workflows must all be changeable by an authorized
user without a code deploy.

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
