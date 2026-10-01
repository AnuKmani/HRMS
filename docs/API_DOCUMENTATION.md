# API Documentation

> **Status:** Phases 1–10. §1 conventions, §2.1 authentication (Phase 3),
> §2.2–§2.3 the organisation modules (Phase 4), §2.4–§2.5 attendance, site
> visits and movement (Phase 5), §2.6 site activity and daily reports
> (Phase 7) and §2.7–§2.9 timesheets, overtime, approval workflows, leave,
> certificates and holidays (Phase 6) are **live**, as are §2.10 payroll,
> loans and salary documents (Phase 8), §2.10a expenses and receipts
> (Phase 9) and §2.11 employee documents, expiry and onboarding
> (Phase 10). Everything from §2.11a onward is the contract the remaining
> phases build against. See §6 for the per-section status.

**Base URL (development):** `http://127.0.0.1:8000/api/v1/`
**Content type:** `application/json` (except file uploads: `multipart/form-data`)

---

## 1. Conventions

### 1.1 Versioning

All routes are prefixed `/api/v1/`. Breaking changes get a new version prefix;
additive changes do not.

### 1.2 HTTP methods

| Method | Meaning |
|---|---|
| `GET` | Read / list |
| `POST` | Create, or perform an action |
| `PUT` / `PATCH` | Update |
| `DELETE` | Soft delete |

### 1.3 Success envelope

```json
{
    "success": true,
    "message": "Attendance checked in successfully.",
    "data": { }
}
```

### 1.4 Validation error — HTTP 422

```json
{
    "success": false,
    "message": "Validation failed.",
    "errors": {
        "site_id": ["The site id field is required."],
        "latitude": ["The latitude must be between -90 and 90."]
    }
}
```

`errors` is keyed by field name so the Flutter form can attach messages directly.

### 1.5 Error envelope — 401 / 403 / 404 / 429 / 500

```json
{
    "success": false,
    "message": "Unauthenticated.",
    "errors": {}
}
```

`errors` is **always a JSON object** — `{}` when there is nothing
field-specific to say. Flutter can therefore read it as a map without a null
check. (Status `0` in the table below is not an HTTP status: it is the code the
client assigns when no response arrived at all.)

### 1.6 Status codes

| Code | Meaning | Flutter behaviour |
|---|---|---|
| `200` | OK | Parse `data` |
| `201` | Created | Parse `data`, refresh list |
| `401` | Token expired / invalid | Clear the stored token, return to login. There is no refresh token in this API — a dead session is re-established by signing in again |
| `403` | Authenticated but not permitted | Show "not authorized" state, do **not** log out |
| `404` | Not found | Show empty / not-found state |
| `409` | Conflict — the state already exists | Show the message; do **not** queue or retry. Attendance answers 409 for a second check-in on the same day, a duplicate check-out, an unclosed prior-day row, and a `client_event_id` reused with a different payload |
| `422` | Validation failed | Map `errors` onto form fields |
| `429` | Rate limited | Show cooldown message, respect `Retry-After` |
| `500` | Server error | Show generic failure, log details for developers |
| `0` | No network / timeout | Offer retry, use offline queue if applicable |

### 1.7 Pagination

**Query params:** `page`, `per_page` (default 15, max 100), `sort`, `direction`,
plus resource-specific filters.

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "items": [ ],
        "meta": { "current_page": 1, "per_page": 15, "total": 132, "last_page": 9, "has_next": true }
    }
}
```

`per_page` is clamped server-side to a maximum of **100**, whatever the client
asks for. `has_next` is computed for the client rather than left to be
re-derived from `page < last_page`, so a cursor-style screen and a page-number
screen agree about when the list ends.

### 1.8 Filtering

```
GET /api/v1/employees?search=ahmed&department_id=3&status=active&sort=created_at&direction=desc
GET /api/v1/attendance?from=2026-09-01&to=2026-09-30&site_id=4&status=late
GET /api/v1/leave?status=pending&leave_type_id=1&year=2026&sort=start_date&direction=desc
GET /api/v1/holidays?from=2026-09-01&to=2026-12-31&type=site&status=active&sort=date
GET /api/v1/overtime?status=approved&payroll_eligible=true
GET /api/v1/timesheets?from=2026-09-01&to=2026-09-30&status=incomplete
```

An absent parameter and an empty one are **different questions**: `status=`
means "status is the empty string" and matches nothing, while no `status` at
all means "every status". A client that wants to clear a filter must delete
the key, not blank it.

`sort` is always an allow-listed column, never a raw identifier; an unknown
`sort` falls back to that resource's default rather than erroring.

### 1.9 Authentication

```
Authorization: Bearer <sanctum-token>
Accept: application/json
```

Tokens are issued at login and stored in **secure storage** (Keychain / Keystore) —
never in plain preferences.

Behaviour that holds across every authenticated endpoint:

- The token is returned **once**, by `POST /auth/login`. Sanctum stores only a
  SHA-256 hash, so no later response can include it again.
- One token per `device_name`. Signing in with a name that already exists
  deletes the old token first, so a handset never accumulates sessions it
  cannot see.
- Any request presenting a rejected token gets `401` in the standard envelope.
  A client should treat that as "this session is over", clear its stored token,
  and return to `/login` — not as a transient error worth retrying.
- A `403` is **not** a session failure. The token is still good; the user
  simply lacks the permission. Signing them out would be wrong.

---

## 2. Endpoints

> **Implemented:** §2.1 (Phase 3), §2.2–§2.3 (Phase 4), §2.4–§2.5 (Phase 5),
> §2.6 in **Phase 7**, §2.7 (timesheets + overtime), §2.8 (leave), §2.9
> (holidays) and §2.7's approval-workflows in **Phase 6**, §2.10 in
> **Phase 8**, §2.10a in **Phase 9** and §2.11 in **Phase 10**. Everything
> still pending is listed here as the contract to build against.

**Two gates, both live on every Phase 4, Phase 5, Phase 6, Phase 7, Phase 8,
Phase 9 and Phase 10 route**
(the four exceptions are named in §2.7 / §2.9 / §2.11 — holiday reads, the
certificate read/write, and the bank-account pair, which are policy-only on
purpose):

| Gate | Runs | Answers |
|---|---|---|
| `permission:…` middleware | before the controller | "may this role open the module at all?" — an unauthorised caller never reaches a query |
| `$this->authorize()` → policy | inside the controller | "may they touch *this* record?" — row-level, and it also guards the routes below that carry no middleware |

`GET /employees/{id}` and `GET /employee-site-assignments/{id}` deliberately
have **no** `permission:` middleware: an ordinary employee reading their own
profile holds no `*.view` permission, and the policy is what separates
"yours" from "everybody else's". Everything else is coarse-gated first.

Phase 5 follows the same rule for a whole second set of routes —
`GET /attendance/today`, `POST /attendance/check-in`,
`POST /attendance/check-out`, `GET /site-visits/today`,
`POST /site-visits/start`, `POST /site-visits/{siteVisit}/end` and
`GET /movement/today` — because recording your own day is not a privilege
anybody grants you. Holding `attendance.view` gets you *your own rows and no
others*; the policy, not a permission name, is what stops one employee
reading another's attendance. `GET /attendance` and `GET /site-visits` are
coarse-gated first, as elsewhere.

Phase 7 adds no exception of that kind — both report families are
coarse-gated first and row-scoped by the policy — but it does add one route
that exists because a *different* gate would have been wrong:
`GET /site-activity-reports/reportable-sites`. It answers "which sites may I
write about?" for the field-reporting form, because `GET /sites` needs
`sites.view`, which no Employee holds, and filing your own day's work is
precisely what an Employee is there to do.

**Phase 10 adds two policy-only pairs**, both for the same reason:
`GET|PUT /employees/{employee}/bank-account` carries **no `permission:` at
all** — bank details are row-scoped by `EmployeePolicy::viewBankAccount` /
`updateBankAccount` plus `EMPLOYEE_Bank_Details`, and there is no role
whose *job* is to read everybody's IBAN — and `GET /document-types` runs on
`documents.view` but is scoped to types the caller may actually file against.
`GET /employee-documents` is coarse-gated on `documents.view` and then
row-scoped by `Visibility::mayViewOthersDocuments()`, which needs
**`documents.view` *and* `documents.manage`**: `employees.view` is
deliberately not a third door in, so a Project Manager who may see a team
does not thereby see its passports.

### 2.1 Authentication ✅ (Phase 3)

| Method | Path | Gate | Notes |
|---|---|---|---|
| POST | `/auth/login` | `throttle:login` | Token + user profile + permissions |
| POST | `/auth/logout` | `auth:sanctum` | Revokes the token this request presented |
| GET | `/auth/me` | `auth:sanctum` | Current user, roles, permissions |
| GET | `/auth/sessions` | `auth:sanctum` | List this account's device tokens |
| DELETE | `/auth/sessions/{id}` | `auth:sanctum` | Revoke a specific device |
| POST | `/auth/change-password` | `auth:sanctum` | Requires current password; signs other devices out |
| POST | `/auth/forgot-password` | `throttle:password_reset` | **501** until a mailer is configured |
| POST | `/auth/reset-password` | `throttle:password_reset` | **501** until a mailer is configured |
| GET | `/roles` | `permission:roles.view` | Read-only role → permission map |

#### `POST /auth/login`

```json
{
    "email": "ada@example.com",
    "password": "…",
    "device_name": "android 9f3c1a2b7d4e5f60"
}
```

`device_name` is optional (`max:190`). Omitting it yields the server default
`API client`, which means every install shares one token slot — send a stable
per-install value, as `SecureDeviceIdentity` in the app does.

**200**

```json
{
    "success": true,
    "message": "Signed in successfully.",
    "data": {
        "token": "1|…",
        "token_type": "Bearer",
        "expires_at": null,
        "user": {
            "id": 7,
            "name": "Ada Lovelace",
            "email": "ada@example.com",
            "status": "active",
            "roles": ["Employee"],
            "permissions": ["attendance.view"],
            "employee": {
                "id": 12,
                "employee_code": "EMP-0012",
                "full_name": "Ada Lovelace",
                "photo_path": null,
                "department": "Engineering",
                "designation": "Staff Engineer",
                "employment_type": "permanent",
                "employment_status": "active"
            }
        }
    }
}
```

`expires_at` is `null` for a token that does not expire, which is what the API
issues today — treat it as "no automatic logout", not as an error.

**401** — identical body for unknown address, wrong password, and a
deactivated account's *shape* of failure. The password is verified first, then
`status`, so the response never confirms that an address exists:

```json
{ "success": false, "message": "The email or password you entered is incorrect.", "errors": {} }
```

**422** — shape only (`email:rfc`, `password` required and `≤255`,
`device_name` optional). Whether the credentials are *correct* is never
validated here, because a 422 would be an account-existence oracle.

**429** — see §3.

#### `GET /auth/me`

`data` is the `user` object above. Use it to restore a session on launch: a
`401` means the stored token is dead and must be discarded; anything else
(network, 500) means "could not ask", and the token should be kept.

#### `POST /auth/logout`

Deletes only the token this request carried. Other devices stay signed in.
`data` is `{}`.

#### `POST /auth/change-password`

```json
{ "current_password": "…", "password": "…", "password_confirmation": "…" }
```

`422` with `current_password: ["The current password you entered is incorrect."]`
when the proof fails. On success every **other** token for the account is
deleted — changing a password ends the sessions it was meant to end.

#### `POST /auth/forgot-password` · `POST /auth/reset-password`

Built, validated and routed, but answered **501** with an honest message until
`PASSWORD_RESET_ENABLED=true` *and* a mailer that can actually deliver are
configured. `MAIL_MAILER=log` writes the link to a log file nobody reads, so
returning `200` would tell the user a reset is on its way when none is.
`forgot-password` deliberately has no `exists:users.email` rule — the endpoint
answers identically for known and unknown addresses.

#### `GET /auth/sessions` · `DELETE /auth/sessions/{id}`

`GET` answers `message: "Active sessions."` with `data` as a **JSON array** —
the one place where `data` is not an object, because a list of devices is a
list:

```json
{
    "id": 41,
    "device": "android 9f3c1a2b7d4e5f60",
    "is_current": true,
    "last_used_at": "2026-09-27T09:14:02.000000Z",
    "created_at": "2026-09-27T08:02:11.000000Z",
    "expires_at": null
}
```

Identifiers and timestamps only — never the stored hash. `DELETE` answers
`"Session revoked."` and only ever touches a token belonging to the caller's
own account; another user's session id is a **404**, not a 403, because a 403
would confirm that the token exists somewhere.

#### `GET /roles`

```json
{
    "success": true,
    "message": "All roles.",
    "data": [
        { "name": "HR Executive", "guard_name": "web", "permissions": ["employees.create", "…"] }
    ]
}
```

### 2.2 Departments, Designations & Employees ✅ (Phase 4)

| Method | Path | Gate |
|---|---|---|
| GET | `/departments` | `permission:departments.view` |
| GET | `/departments/{id}` | `permission:departments.view` |
| POST | `/departments` | `permission:departments.manage` |
| PUT | `/departments/{id}` | `permission:departments.manage` |
| DELETE | `/departments/{id}` | `permission:departments.manage` |
| GET | `/designations` | `permission:designations.view` |
| GET | `/designations/{id}` | `permission:designations.view` |
| POST | `/designations` | `permission:designations.manage` |
| PUT | `/designations/{id}` | `permission:designations.manage` |
| DELETE | `/designations/{id}` | `permission:designations.manage` |
| GET | `/employees` | `permission:employees.view` |
| GET | `/employees/{id}` | *(no middleware — `EmployeePolicy::view` only)* |
| POST | `/employees` | `permission:employees.create` |
| PUT | `/employees/{id}` | `permission:employees.update` |
| DELETE | `/employees/{id}` | `permission:employees.delete` |

**List query params** (all six resources share the shape): `page`, `per_page`
(server clamps to **100**), `sort`, `direction`, `search`, plus the
resource's own filters — `department` *or* `department_id` are accepted as
aliases on designations and employees; `status` on departments, designations,
projects, sites; `employment_status`, `employment_type`, `designation_id`,
`project_id` on employees. An unknown `sort` key falls back to the default
rather than erroring.

**List envelope** (`PaginatedResponse::make`):

```json
{
    "success": true,
    "message": "Employees.",
    "data": {
        "items": [ /* resource objects */ ],
        "meta": { "current_page": 1, "last_page": 4, "per_page": 15, "total": 52, "has_next": true }
    }
}
```

`has_next` is derived server-side so the client's load-more button does not
have to reconstruct it from `page` and `last_page`.

**`GET /employees/{id}` returns `EmployeeDetailResource`** — the projection
that carries `date_of_birth`, `nationality`, `address`, the emergency
contacts, `salary` and `salary_visible`. `GET /employees` and every nested
reference return `EmployeeResource`, which has **no** `salary` key at all:
not `null`, absent. That resource is embedded inside projects (the project
manager) and sites (manager, supervisor), so whatever it carried would travel
into responses gated by `projects.view` rather than `employees.view`.

```json
{
    "id": 12,
    "employee_code": "EMP-0012",
    "first_name": "Ada", "middle_name": null, "last_name": "Lovelace",
    "full_name": "Ada Lovelace",
    "email": "ada@example.com", "phone": null,
    "department_id": 3, "department": { "id": 3, "name": "Engineering" },
    "designation_id": 7, "designation": { "id": 7, "name": "Staff Engineer" },
    "reporting_manager_id": null, "reporting_manager": null,
    "joining_date": "2024-03-01", "employment_type": "permanent",
    "employment_status": "active", "status": "active",
    "salary": 18000.00, "salary_visible": true
}
```

`salary_visible` is `true` only when the caller holds `employees.salary.view`.
A caller without it receives a body where `salary` is absent, and `PUT` with a
`salary` key answers **422** — the gate is re-enforced in
`UpdateEmployeeRequest`, not merely trusted from the session.

**Deleting a department or designation answers 422** while employees still
reference it (`{"employees": ["Reassign the employees before deleting."]}`);
a project answers 422 while it still has sites. Nothing is cascade-deleted
from behind the user's back.

### 2.3 Projects, Sites & Assignments ✅ (Phase 4)

| Method | Path | Gate |
|---|---|---|
| GET | `/projects` | `permission:projects.view` |
| GET | `/projects/{id}` | `permission:projects.view` |
| POST / PUT / DELETE | `/projects` … | `permission:projects.manage` |
| GET | `/sites` | `permission:sites.view` |
| GET | `/sites/{id}` | `permission:sites.view` |
| POST / PUT / DELETE | `/sites` … | `permission:sites.manage` |
| GET | `/employee-site-assignments` | `permission:assignments.view` |
| GET | `/employee-site-assignments/{id}` | *(no middleware — policy only)* |
| POST | `/employee-site-assignments` | `permission:assignments.manage` |
| PUT | `/employee-site-assignments/{id}` | `permission:assignments.manage` |

**There is deliberately no `DELETE /employee-site-assignments/{id}`.** Posting
history is append-only: an assignment is retired by moving its `status` to
`ended` or `cancelled` and setting `end_date`, never by removing the row. An
erase endpoint would be reachable by anyone holding `assignments.manage`,
which is not the same thing as being allowed to rewrite the past.

**Creating an assignment** (`POST`) accepts `employee_id`, `site_id`,
`assignment_type` (`primary` \| `temporary` \| `additional`), `start_date`,
and optionally `end_date`, `notes`, `is_primary`, `latitude`, `longitude`,
`radius_metres`. The response records `created_by`. When a `primary` +
`active` assignment already exists for that employee, the service closes the
predecessor on the day before the new `start_date` (or marks it `cancelled`
if the dates overlap) inside one transaction — one row per day is preserved,
not overwritten.

**Updating an assignment** accepts **only** `status` ∈ `{ended, cancelled}`
and `end_date`. Identity fields (`employee_id`, `site_id`, …) answer a
per-field **422** rather than silently rewriting which relationship the row
describes.

**Geofence fields** must be supplied as a set: `latitude`, `longitude` and
`radius_metres` are accepted together or all absent, and the radius is
bounded by `config/hrms.php` — `GEOFENCE_MIN_RADIUS_METRES` (default 10) to
`GEOFENCE_MAX_RADIUS_METRES` (default 10000).

**Project status values:** `planned`, `active`, `on_hold`, `completed`,
`cancelled`. **Site status:** `active`, `inactive`. **Employee status:**
`active`, `inactive`, `resigned`, `terminated`, `on_leave`. **Employee type:**
`permanent`, `contract`, `probation`, `internship`, `part_time`. No column
uses a MySQL `ENUM`; the values are validated per-request, so adding one later
is a seeder change and not a migration.

`DELETE /projects/{id}` answers **422** while the project still owns sites;
`DELETE /sites/{id}` answers **422** while the site has assignment history.
`photo_path` is never an accepted request field on any of these endpoints —
profile-photo upload arrives with the documents phase.

### 2.4 Attendance ✅ (Phase 5)

All paths are relative to `/api/v1`.

| Method | Path | Gate |
|---|---|---|
| POST | `/attendance/check-in` | policy `checkIn` + `throttle:attendance` |
| POST | `/attendance/check-out` | policy `checkOut` + `throttle:attendance` |
| GET | `/attendance/today` | the caller's own day |
| GET | `/attendance` | `permission:attendance.view`, then policy-scoped rows |
| GET | `/attendance/{attendance}` | policy `view` |
| GET | `/attendance/{attendance}/selfie` | policy `viewSelfie`, `Cache-Control: no-store` |

**What the client may send.** Check-in is `multipart/form-data` and carries
only what the phone in that person's hand could know:

| Field | Rules |
|---|---|
| `site_id` | required, must exist **and** be an active assignment for the caller |
| `latitude` / `longitude` | required, `-90..90` / `-180..180` |
| `accuracy` | optional metres, `0..100000`; the geofence refuses anything above `hrms.attendance.max_gps_accuracy_metres` (default 100) |
| `selfie` | required image, ≤ `hrms.storage.selfie_max_kilobytes` (5120 KB), MIME sniffed, and it must actually **decode** — see §4 |
| `client_event_id` | optional UUID — the idempotency key |
| `source` | `online` or `offline`. `manual` is never accepted from a client |
| `device_reference` | optional string, ≤ 100 chars |

`employee_id`, `attendance_date`, `project_id`, distances, minutes and
`status` are **not accepted fields**. There is nowhere to put them: the
session names the employee, the clock names the day, and
`AttendanceService` derives the rest.

**Check-in request**
```http
POST /api/v1/attendance/check-in
Content-Type: multipart/form-data

site_id=4
latitude=12.971600
longitude=77.594600
accuracy=12.5
client_event_id=9f1c2b7e-4a6d-4f2b-9c1e-0d3b5a7e9f11
source=online
device_reference=android-testphone
selfie=@checkin.jpg;type=image/jpeg
```

**Response — created (HTTP 201)**
```json
{
    "success": true,
    "message": "Checked in.",
    "data": {
        "id": 1042,
        "attendance_date": "2026-09-27",
        "site": {"id": 4, "name": "Whitefield Yard", "code": "WFY-01"},
        "project": {"id": 1, "name": "Metro Line 3", "code": "ML3"},
        "check_in_at": "2026-09-27T09:05:00+00:00",
        "check_in_latitude": "12.9716000",
        "check_in_longitude": "77.5946000",
        "check_in_accuracy": "12.50",
        "check_in_distance": "34.20",
        "has_selfie": true,
        "scheduled_start_at": "2026-09-27T09:00:00+00:00",
        "working_minutes": 0,
        "late_minutes": 0,
        "break_minutes": 0,
        "overtime_minutes": 0,
        "early_departure_minutes": 0,
        "status": "present",
        "source": "online"
    }
}
```

**Response — outside the geofence (HTTP 422)**
```json
{
    "success": false,
    "message": "The given data was invalid.",
    "errors": {
        "location": ["You are 412 m from Whitefield Yard; the allowed radius is 100 m."]
    }
}
```

The `location` field carries every geofence refusal — `invalid_coordinates`,
`poor_accuracy`, `site_not_configured` and `outside_geofence` — each with
its own sentence. The distance inside the message is computed by the server
from the coordinates it was sent; a distance field in the request would not
be read.

**Other answers worth knowing**

| Status | When |
|---|---|
| 201 | A replay of an already-accepted `client_event_id` — the original row, unchanged |
| 403 | The site is not an active assignment for the caller, or the employee/site is inactive |
| 409 | A second check-in on the same `(employee_id, attendance_date)`, or an open prior-day row |
| 409 | A `client_event_id` reused with a different payload |
| 422 | Geofence refusal (`location`), or a check-out with no check-in (`check_out`), or a check-out at a different site (`site_id`) |
| 429 | `attendance` limiter — 30 write requests per minute, keyed by user |

Check-out is the same shape minus `selfie` (`site_id`, `latitude`,
`longitude`, `accuracy`, `client_event_id`, `source`, `device_reference`).
It finalises `working_minutes`, `break_minutes`, `late_minutes`,
`early_departure_minutes` and provisional `overtime_minutes`, and writes
`present`, `late` or `incomplete` through `AttendanceStatusCalculator` —
the single place status is decided.

`GET /attendance/today` answers with:

```json
{
    "date": "2026-09-27",
    "server_time": "2026-09-27T09:12:41+00:00",
    "employee_id": 7,
    "attendance": { "…": "AttendanceResource, or null" },
    "open_attendance": null,
    "checked_in": true,
    "checked_out": false,
    "site": {"id": 4, "name": "Whitefield Yard", "code": "WFY-01",
             "project_id": 1, "project_name": "Metro Line 3",
             "latitude": "12.9716000", "longitude": "77.5946000",
             "geofence_radius": "100.00"},
    "sites": ["…one siteSummary per active assignment"],
    "shift": {
        "shift_id": 2,
        "starts_at": "2026-09-27 09:00:00",
        "ends_at": "2026-09-27 18:00:00",
        "grace_minutes": 10,
        "break_minutes": 60,
        "minimum_working_minutes": 480,
        "overtime_threshold_minutes": 30,
        "crosses_midnight": false
    },
    "working_minutes": 74,
    "late_minutes": 0,
    "max_gps_accuracy_metres": 100,
    "can_check_in": false,
    "can_check_out": true,
    "can_start_site_visit": true
}
```

`geofence_radius` is already defaulted server-side (to
`attendance.default_geofence_radius`, 100 m) when the site column is null,
so the phone never has to invent a boundary. `max_gps_accuracy_metres` is
sent down for the same reason: the advisory check on the device uses the
server's number instead of a copy that would drift.

`GET /attendance` goes through `PaginatedResponse` and accepts `date_from`,
`date_to`, `employee_id`, `project_id`, `site_id`, `status` and `search`.
A caller without `attendance.manage` or `employees.view` is pinned to their
own rows regardless of the filters sent; a project manager or site
supervisor is scoped to the projects they manage and the sites they run.

**Selfies** live on the `local` disk at
`storage/app/private/attendance-selfies/{employeeId}/{uuid}.jpg` and are
streamed only by `GET /attendance/{attendance}/selfie` under the
`viewSelfie` policy. No endpoint returns a path, a base64 blob or a public
URL, and no image is ever collected from someone else's record. There is no
facial recognition in this system.

### 2.5 Site Visits & Movement ✅ (Phase 5)

| Method | Path | Gate |
|---|---|---|
| POST | `/site-visits/start` | policy `start` + `throttle:attendance` |
| POST | `/site-visits/{siteVisit}/end` | policy `end` + `throttle:attendance` |
| GET | `/site-visits/today` | the caller's own episodes → `data.items` |
| GET | `/site-visits` | `permission:attendance.view`, then policy-scoped |
| GET | `/movement/today` | the caller's own day |

`POST /site-visits/start` takes `site_id`, `latitude`, `longitude`,
`accuracy`, `purpose` (required, ≤ 150 chars), optional `remarks`,
`client_event_id` and `device_reference`. It answers **409** while one of
the caller's visits is still open and **403** when the site is not assigned
to them. `POST /site-visits/{siteVisit}/end` takes `latitude`, `longitude`,
`accuracy`, optional `remarks` and `client_event_id` — no `site_id`, since
the row already knows where it is.

Both ends re-run the geofence against their own coordinates, so a visit
that starts inside the boundary cannot be closed from outside it.

A visit is a **bounded two-point episode** — a start and an end, each with
its own coordinates and accuracy — never a track. There is no continuous
location collection: nothing polls, nothing uploads in the background, and
the app requests foreground permission only.

`GET /movement/today` is the chronological day, one event per real thing
that happened:

```json
{
    "success": true,
    "message": "Movement timeline retrieved.",
    "data": {
        "date": "2026-09-27",
        "events": [
            {"type": "check_in", "at": "2026-09-27T09:05:00+00:00",
             "record_id": 1042, "site_id": 4, "site_name": "Whitefield Yard",
             "project_id": 1, "project_name": "Metro Line 3",
             "status": "present", "label": "Checked in at Whitefield Yard"},
            {"type": "site_visit_start", "at": "2026-09-27T10:20:00+00:00",
             "record_id": 300, "site_name": "Metro Line 3",
             "status": "open", "label": "Started a site visit at Metro Line 3",
             "purpose": "Material delivery"},
            {"type": "site_visit_end", "at": "2026-09-27T11:05:00+00:00",
             "record_id": 300, "status": "closed",
             "label": "Ended the site visit at Metro Line 3",
             "duration_minutes": 45},
            {"type": "check_out", "at": "2026-09-27T18:02:00+00:00",
             "record_id": 1042, "status": "present",
             "label": "Checked out of Whitefield Yard",
             "working_minutes": 537}
        ]
    }
}
```

**What Phase 5 deliberately does not expose.** There is no
`/attendance/{id}/override` endpoint and no batch `/attendance/sync`
endpoint. Offline events arrive one at a time through `check-in`,
`check-out` and `site-visits/start`, each carrying the `client_event_id`
that makes the replay safe, and are revalidated exactly as though they had
arrived live. `manually_adjusted` exists in the schema with a reserved
status value so the future override/audit path can be added without
migrating the table — but no UI, no endpoint and no permission gate around
it yet.

### 2.6 Site Activity & Daily Reports — (Phase 7 ✅)

| Method | Path | Gate |
|---|---|---|
| GET | `/site-activity-reports` | `site_activity_reports.view` + row scope |
| GET | `/site-activity-reports/reportable-sites` | `site_activity_reports.view` |
| GET | `/site-activity-reports/{report}` | `site_activity_reports.view` + row scope |
| POST | `/site-activity-reports` | `site_activity_reports.create` |
| PUT | `/site-activity-reports/{report}` | `site_activity_reports.update` + row scope |
| POST | `/site-activity-reports/{report}/submit` | `site_activity_reports.update` + own row |
| POST | `/site-activity-reports/{report}/photos` | `site_activity_reports.update` + row scope |
| GET | `/site-activity-reports/{report}/photos/{photo}` | `site_activity_reports.view` + row scope |
| DELETE | `/site-activity-reports/{report}/photos/{photo}` | `site_activity_reports.update` + row scope |
| GET | `/daily-site-reports` | `daily_site_reports.view` + row scope |
| GET | `/daily-site-reports/{report}` | `daily_site_reports.view` + row scope |
| POST | `/daily-site-reports` | `daily_site_reports.create` |
| PUT | `/daily-site-reports/{report}` | `daily_site_reports.update` + row scope |
| POST | `/daily-site-reports/{report}/submit` | `daily_site_reports.update` + row scope |
| GET | `/daily-site-reports/{report}/pdf` | **`daily_site_reports.pdf`** + `DailySiteReportPolicy::pdf` |
| POST | `/daily-site-reports/{report}/photos` | `daily_site_reports.update` + row scope |
| GET | `/daily-site-reports/{report}/photos/{photo}` | `daily_site_reports.view` + row scope |
| DELETE | `/daily-site-reports/{report}/photos/{photo}` | `daily_site_reports.update` + row scope |

18 routes. Every `{report}` is `->whereNumber(...)` and every sub-route is
registered *before* `/{report}`, so `…/submit` and `…/photos` can never be
swallowed as an id.

**The author is never a field.** `employee_id` is absent from every request
body and is derived from the authenticated user; `created_by` likewise on the
daily report. A body that carries either is simply ignored. The project is
also not trusted as typed: both stores require `project_id` *and* `site_id`,
and `ValidatesReportSite` rejects any pair whose project does not own the
site with a `422` on `project_id` — the service then stores `$site->project_id`
regardless, so a wrong pairing can never reach the row.

**Status is a verb, not a field.** The only transitions are
`draft → submitted`, taken by `POST …/submit`. There is no `status` key in
any create or update body, and an edit to a report that is already filed
answers **`409 Conflict`** rather than silently reopening it. `approved_at`
is reserved for a later approval pass; Phase 7 has no approve endpoint.

**GPS travels at submit, not at save.** A draft may be filed without a
reading, and may carry one if it has it. Submitting requires all three of
`latitude`, `longitude` **and** `gps_accuracy`, validates them
(`0,0`, non-finite and over `hrms.attendance.max_gps_accuracy_metres` are
refused), and takes them from *that request* — the server does not merge the
stored fix, so the phone must take the reading at the moment it submits.

**Filters**, all on `GET /site-activity-reports`:
`report_date_from` · `report_date_to` · `employee_id` · `project_id` ·
`site_id` · `work_category` · `status`. On `GET /daily-site-reports`:
`report_date_from` · `report_date_to` · `project_id` · `site_id` ·
`status` · `created_by`. Both paginate in the usual
`{items, meta}` envelope, default `per_page = 15`, max `100`.

**One official report per site per day.** A duplicate `POST /daily-site-reports`
answers `422` with `report_date`, enforced three times over: `Rule::unique`
scoped to `site_id` (with `ignore()` on update), the `dsr_site_date_unique`
index, and a `QueryException` guard in `DailySiteReportService` for callers
that bypass the request. Workforce categories are **rows, not a fixed list** —
`manpower` is an array of `{category, count}` and `total_manpower` is
required only when `manpower` is absent (a caller with rows cannot also
assert a total that disagrees with them).

**Photographs are never addresses.** `POST …/photos` takes a `photos[]`
multipart batch of at most **6** per request (12 per report, over which it
answers `422` on `photos`) plus one optional `caption`. The response carries
only `{id, caption, sort_order, mime_type, size_bytes, created_at}` — no
path, no URL, no `disk`. To see the pixels you use
`GET …/photos/{photo}`, which is permission-checked like any other row and
serves the bytes with `no-store`.

**The PDF is generated on demand and never stored.**
`GET /daily-site-reports/{report}/pdf` answers `Content-Type: application/pdf`
with `Content-Disposition: inline; filename=daily-site-report-{id}-{ddMMyyyy}.pdf`,
`Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and
`X-Content-Type-Options: nosniff`. There is no `/pdf` on the activity
reports, and no endpoint returns a saved PDF, because there are none to
return.

### 2.7 Shifts, Timesheets, Overtime & Approval Workflows — (Phase 6 ✅, shifts pending)

| Method | Path | Gate |
|---|---|---|
| GET | `/timesheets` | `timesheets.view` + row scope |
| GET | `/timesheets/{timesheet}` | `timesheets.view` + row scope |
| POST | `/timesheets/generate` | `timesheets.manage` — `{from, to}` (max 31 days) |
| GET | `/overtime` | `overtime.view` + row scope |
| POST | `/overtime` | `overtime.create` |
| GET/PUT | `/overtime/{id}` | `overtime.view` / own draft or `overtime.manage` |
| POST | `/overtime/{id}/submit` | own draft |
| POST | `/overtime/{id}/approve` | `overtime.approve` + **current step** |
| POST | `/overtime/{id}/reject` | `overtime.approve` + **current step** |
| POST | `/overtime/{id}/cancel` | owner or `overtime.manage` |
| GET/POST/PUT | `/approval-workflows` | `approvals.view` / `approvals.manage` |
| GET/POST/PUT | `/shifts` … | `attendance.manage` · ⬜ not built |

**Timesheets are derived, not entered.** There is no `POST /timesheets` and
no approval endpoint: `POST /timesheets/generate` re-reads `attendances` for
the window and upserts one snapshot per working day, so running it twice
refreshes rather than duplicates. `status` (`open` / `complete` /
`incomplete`) is a property of the *day*, not something anyone approves.

**Overtime runs on the same engine as leave.** The chain is frozen at
`submit`; `approve` carries an optional `approved_minutes` (1–1440) so the
approver may grant less than was asked. Omitting it grants the whole request.
`payroll_eligible` becomes `true` **only** on the approval that completes the
chain — nothing in Phase 6 computes money from it.

### 2.8 Leave — (Phase 6 ✅)

| Method | Path | Gate |
|---|---|---|
| GET | `/leave` | `leave.view` + row scope |
| POST | `/leave` | `leave.create` |
| GET/PUT | `/leave/{id}` | `leave.view` / own draft or `leave.manage` |
| POST | `/leave/{id}/submit` | owner — freezes the approval chain |
| POST | `/leave/{id}/approve` | `leave.approve` + **current step**, never yourself |
| POST | `/leave/{id}/reject` | `leave.approve` + **current step**, `remarks` required |
| POST | `/leave/{id}/cancel` | owner (draft or pending) or `leave.manage` |
| POST | `/leave/{id}/certificate` | owner or `leave.manage`, multipart `certificate` |
| GET | `/leave/{id}/certificate` | same, returns raw bytes (`Content-Type` from the file) |
| GET/POST/PUT/DELETE | `/leave-types` | `leave.view` / `leave.manage` |
| GET/PUT | `/leave-balances` | `leave.balance.view` / `leave.balance.manage` |

Statuses are exactly `draft · pending · approved · rejected · cancelled · lop`
and every transition goes through a service method — a controller cannot write
a status directly.

- **Day counts are the server's.** `requested_days` comes from
  `LeaveDayCalculator` (weekends + holiday calendar + halves) and is echoed
  back; a client that recomputed it locally would disagree with the
  reservation the API just made.
- **Balances are transactional.** The row is locked, the reservation written,
  and the whole thing rolled back if any part fails. A balance goes negative
  only when the leave type says `allow_negative`.
- **Certificate deadline** defaults to `leave.sick_certificate_deadline_days`
  (2) and is overridden per leave type by `document_deadline_days` when that
  is `> 0`. Missing it converts the request to `lop` **server-side** — see
  §2.8a.
- **No self-approval**, ever, and only the approver at the *current* step may
  act; earlier and later steps see the buttons but the server refuses.

> `POST /leave/{id}/certificate` is deliberately **not** rate-limited by a
> new limiter: it is behind a session plus a policy check plus the same
> MIME/size validation as every other upload. See §3.

### 2.8a Sick certificates & LOP — (Phase 6 ✅)

```
POST   /leave/{id}/certificate      multipart: certificate
GET    /leave/{id}/certificate      raw bytes, never a storage path
```

Upload: `pdf · jpg · jpeg · png · webp`, size from `certificate_max_kilobytes`
(default **5120 KB**), content sniffed byte-by-byte by `CertificateContent` so
a renamed `.exe` fails as a field error. The stored name is server-minted;
the client's filename is never read.

`EnforceSickCertificateDeadlines` runs hourly at :17 (queued, unique,
idempotent). Any sick request past its deadline with no file becomes `lop`
with `lop_days`, `lop_reason`, `lop_applied_at` recorded and
`LeaveConvertedToLop` dispatched — the paid leave type's reservation is
released in the same transaction. `GET /leave?status=lop` lists them.
**The figure is consumed by payroll in Phase 8** — `PayrollCalculationService`
prices `lop_days` into a `lop` line on the salary slip (see §2.10); the leave
module itself still computes no money.

### 2.9 Holidays — (Phase 6 ✅)

| Method | Path | Gate |
|---|---|---|
| GET | `/holidays` | **any signed-in account** (no `permission:` middleware) |
| GET | `/holidays/{id}` | same |
| POST | `/holidays` | `holidays.manage` |
| PUT | `/holidays/{id}` | `holidays.manage` |

Filters: `type` (`public · company · site`), `status` (`active · inactive`),
`from`, `to`, `search` (alias `q`), `sort` (`date` default, `name`, `type`,
`created_at`), `direction`.

**There is no DELETE** — a holiday the year's leave maths already depended on
cannot be silently erased, so it is retired with `status=inactive` instead
(→ `405` if you try). A duplicate on `(date, type, site_id)` is refused with
`422` — including a real `IS NULL` check for the company-wide rows.

### 2.10 Payroll, Loans & Salary Documents — (Phase 8 ✅)

**36 routes.** Nothing in this section is deleted or edited in place: the
money ladder only climbs, and every step is its own endpoint so that refusing
a transition (409) is a different answer from refusing a caller (403).

#### Payroll runs

| Method | Path | Gate |
|---|---|---|
| GET | `/payroll?year=2026&month=9` | `payroll.view` |
| GET | `/payroll/{payroll}` | `payroll.view` + policy (own row without it) |
| GET | `/payroll/summary?year=2026&month=9` | `payroll.summary.view` |
| POST | `/payroll/process` | `payroll.process` |
| POST | `/payroll/{payroll}/recalculate` | `payroll.process` |
| POST | `/payroll/{payroll}/review` | `payroll.manage` |
| POST | `/payroll/{payroll}/finalize` | `payroll.manage` |
| POST | `/payroll/{payroll}/lock` | `payroll.lock` |

`draft → calculated → reviewed → processed → locked`, one way, each transition
its own endpoint. A row already at or beyond the step you asked for is left
alone — a second `POST /payroll/process` reports `updated: 0` and the rows it
skipped rather than re-pricing them. **A locked row is never recalculated**
(409), and no endpoint moves a row backwards.

`/payroll/summary` returns `year, month, employee_count, gross_payroll,
total_deductions, net_payroll, currency` and **no names** — it is the only
thing `payroll.summary.view` gives Management, so it must not smuggle rows
behind the totals.

A row with no salary on record comes back with `status: draft`,
`net_salary: "0.00"` **and** `blocked_reason`; the reason, not the zero, is
what the UI is expected to show.

`net_salary` below zero is still possible, but **never because of a
repayment**: loan and salary-advance installments are sized against
`payroll.minimum_net_salary` (default `0`), so the most a run will take is
what leaves the net on or above that floor — the installment then reports
`status: partially_deducted` with the part that did not fit carried forward.
Loss of pay and approved adjustments are *not* rewritten to protect the
floor; if they alone push the row under it, the figure is passed through as
it stands, because clamping it would make the slip lie about work that was
or was not done.

#### The calculation

Everything is derived server-side by `PayrollCalculationService` — a client
sends no figures of its own. `App\Support\Money` (scale 2, half-away-from-zero)
and the one currency formatter are the only places a number is rounded or
printed.

| Setting | Default | Effect |
|---|---|---|
| `payroll.lop_divisor_mode` | `fixed` | `fixed`, or `working_days` (the period's own working days) |
| `payroll.lop_divisor` | `30` | Also the divisor that derives the daily rate, and therefore the hourly one (`basic / divisor / daily_hours`, default 8) |
| `payroll.overtime_rate_multiplier` | `1.5` | Applied to the derived hourly rate |
| `payroll.minimum_net_salary` | `0` | The floor a run may not pay below. `room = gross − (LOP + approved adjustments) − floor`, and loan/advance installments may take no more than `room` |

Overtime is priced only for entries that are **`approved` *and*
`payroll_eligible`**. The multiplier is a generic engine parameter — the
UAE/statutory rates a production deployment must use have **not** been wired
in and must be validated before go-live; so must any statutory reading of
the floor, which is why both are settings and neither is an assumed legal
value. `lop_days` from Phase 6 is priced
into a `lop` line here; leave taken unpaid appears as `leave_unpaid`.

#### Allowances & adjustments

| Method | Path | Gate |
|---|---|---|
| GET | `/allowances` | `payroll.view` (own rows without it) |
| POST / PUT / DELETE | `/allowances` · `/allowances/{allowance}` | `payroll.manage` |
| GET | `/payroll-adjustments` · `/payroll-adjustments/{adjustment}` | `payroll.view` |
| POST / PUT | `/payroll-adjustments` · `/{adjustment}` | `payroll.manage` |
| POST | `/payroll-adjustments/{adjustment}/approve` · `/reject` · `/cancel` | `payroll.manage` |

An allowance is `monthly` or `one_time` and applies for as long as its dates
say. An adjustment — a bonus, an other-deduction, an `adjustment` — **enters
payroll only once it is `approved`**: `pending`, `rejected` and `cancelled`
rows are computed nowhere. There is no DELETE on an adjustment either:
cancelling it is the record.

#### Salary slips

| Method | Path | Gate |
|---|---|---|
| GET | `/salary-slips` | `salary_slips.view` (returns `PayrollResource`) |
| GET | `/salary-slips/{payroll}/pdf` | `salary_slips.view` + row scope |

A separate family from `payroll.*` on purpose, so a role can be given its own
payslips without being given the ledger. The PDF is rendered **on demand from
the row and never stored**: no file, no URL, no path, `Cache-Control:
no-store`. It is the locked payroll that prints, so a slip cannot go stale
behind a recalculation.

#### Loans & salary advances

| Method | Path | Gate |
|---|---|---|
| GET | `/loans` · `/loans/{loan}` | `loans.view` |
| POST | `/loans` | `loans.create` (defaults `employee_id` to the caller) |
| POST | `/loans/{loan}/submit` | `loans.create` |
| POST | `/loans/{loan}/approve` · `/reject` | `loans.approve` |
| POST | `/loans/{loan}/cancel` | `loans.view` |
| PUT | `/loans/{loan}` | `loans.view` + **draft only** |

`draft → pending → approved → active → completed`, with `rejected` and
`cancelled` as the branches. The repayment schedule is minted **at approval,
inside the same transaction**, and never re-split afterwards; the last
installment carries whatever the even split leaves over. `employee_id` is
`required` on POST and `prohibited` on PUT — the identity of the borrower is
not an editable field.

Installments are `pending → partially_deducted → deducted` (or `skipped` /
`adjusted` by a human), and every figure the schedule reports is a separate
question with its own key:

| Key | Meaning |
|---|---|
| `amount` | what the schedule says is due (unchanged, never re-split) |
| `deducted_amount` | what pay runs have taken of it so far |
| `remaining_amount` | `amount − deducted_amount` — what is still owed **on this installment** |
| `status` | `pending` (nothing taken) · `partially_deducted` (some taken, rest carries forward) · `deducted` (nothing left to ask for) |

The loan's own `outstanding_balance` is the fourth figure — what is left of
the **loan** — and it moves by what was actually taken, never by what was
merely due.

A run may take a share of an installment rather than the whole of it: the
amount is `min(remaining, room)`, where `room` is what is left above
`payroll.minimum_net_salary` (default `0`), and installments are offered
oldest-first. The remainder stays outstanding and is offered to the next
run — along with any older installment that is now overdue, so a payment
the floor blocked last month is collected this month rather than lost.
Every claim re-reads the row with `SELECT … FOR UPDATE`, clamps itself to
what is still outstanding, and records its own share on the payroll's
`payroll_items` lines (`metadata.scheduled_amount` /
`deducted_amount` / `remaining_amount`), which is what makes a
recalculation give back *one month's* share of a shared installment without
touching another's. A `deducted` row therefore cannot be taken twice by two
concurrent runs, and `payroll_id` names the run that took it most recently.

`next_installment` is the oldest installment that still owes something —
a partial remainder included — and carries `amount` **and**
`remaining_amount`, so a screen can say what the next deduction actually
will be.

**A locked row's deduction is fixed.** `recalculate` on `reviewed`,
`processed` or `locked` is a 409, and a second run counts such rows as
`skipped`, so retuning `payroll.minimum_net_salary` afterwards can never
re-cut a deduction somebody has already been paid from.

#### Salary certificate requests

| Method | Path | Gate |
|---|---|---|
| GET | `/salary-certificate-requests` · `/{id}` | `salary_certificates.view` |
| POST | `/salary-certificate-requests` | `salary_certificates.view` (**asking is not a second grant**) |
| POST | `/{id}/approve` · `/{id}/reject` | `salary_certificates.manage` |
| POST | `/{id}/cancel` | `salary_certificates.view` |
| GET | `/{id}/pdf` | `salary_certificates.view` + policy |

`pending → approved → generated`, with `rejected` and `cancelled` as
branches. The reference is minted as `SAL-CERT-{id:06d}`. The PDF is rendered
first and `markGenerated()` runs after, so a failed render leaves the row
`approved` and answerable again; `generated_at` moves **once**. The
resource's `can_issue` is the server's own answer (grant **and** state) and
is the only gate a client should apply — a client that re-derives it from
`status` will offer a button the API refuses.

Asking for one for a **colleague** needs an explicit `employee_id`, which the
Flutter form does not offer yet (see `docs/FLUTTER_GUIDE.md`); the API does.

#### What is *not* here yet

`/documents`, `/training`, `/assets` (Phase 10) and
`/notifications` (Phase 11) remain contracts, as §2.11–§2.13 say
(`/expenses` left this list in Phase 9 and is documented in §2.10a below).
Phase 8 adds
**no rate limiter**: none of these routes accepts a credential, a file or an
unbounded body — see §3.

### 2.10a Expenses & Receipts — (Phase 9 ✅)

**13 routes.** A claim is the same five-state object leave and overtime are
— `draft → pending → approved | rejected | cancelled` — and it reaches each
one only through `ExpenseService`: `submit`, `approve`, `reject` and
`cancel` are service methods, each inside a DB transaction, so no controller
writes a status even by accident. Money is a **decimal string end to end**
— `DECIMAL(12,2)`, `App\Support\Money`, `Money::round()` — and `amount` is
validated `numeric`, `> 0`, `<= 99999999.99`, which is the largest value
the column can hold rather than a number somebody chose.

#### Claims

| Method | Path | Gate |
|---|---|---|
| GET | `/expenses` | `expenses.view` + row scope |
| GET | `/expenses/summary` | `expenses.view` + row scope |
| GET | `/expenses/{expense}` | `expenses.view` + policy |
| POST | `/expenses` | `expenses.create` |
| PUT | `/expenses/{expense}` | `expenses.update` + policy |
| POST | `/expenses/{expense}/submit` | `expenses.create` + own claim or `expenses.manage` |
| POST | `/expenses/{expense}/cancel` | `expenses.create` + own claim or `expenses.manage` |
| POST | `/expenses/{expense}/approve` | `expenses.approve` + **current link**, never your own |
| POST | `/expenses/{expense}/reject` | `expenses.approve` + **current link**, `remarks` required |

`expenses/summary` is declared before `expenses/{expense}` — the Phase 7
ordering rule again — so `/summary` can never be swallowed as an id.
Reading fails closed to *your own*, exactly as payroll and loans do and
deliberately unlike leave and overtime: `Visibility` narrows the rows, so
`expenses.view` alone never hands an Employee a colleague's spend.

**`POST /expenses`** → `201`:

```json
{
    "expense_date": "2026-09-28",
    "expense_category_id": 3,
    "project_id": 1,
    "site_id": 7,
    "amount": "250.00",
    "currency": "AED",
    "description": "Taxi fare to the client site."
}
```

| Field | Rule |
|---|---|
| `expense_date` | required, `Y-m-d`, not in the future — a claim is money already spent; a future date is a plan |
| `expense_category_id` | required, must exist **and still be active** |
| `project_id`, `site_id` | nullable, must exist — and a `site_id` whose project is not the project sent answers `422` on `site_id` (`That site does not belong to the selected project.`) |
| `amount` | required, `numeric`, `> 0`, `<= 99999999.99`, echoed back as a decimal string |
| `currency` | required, exactly three letters, **normalised to upper case before any rule runs**, and must be one of `system.supported_currencies` (`This company does not accept claims filed in that currency.` otherwise). The default the form offers is `system.currency`, read from **`GET /client-settings`** (§2.10b) — never a value baked into the app. `PUT` also accepts the code the claim was already filed in, so narrowing the setting cannot lock a draft out of being corrected |
| `description` | required, 3–500 characters |
| `employee_id`, `status` | **prohibited** — the claimant is the bearer token and the lifecycle is the five service methods above. Either key buys a `422`, not a silently dropped field the client believed it had set |

`PUT /expenses/{expense}` takes the same shape with every field `sometimes`
and `employee_id` / `status` still prohibited; the service answers it with
**409** once the claim is no longer a draft. On both routes the claim is
never about anyone else: the claimant is derived from the token, and
site↔project consistency plus `Visibility::mayClaimExpenseAt()` (a posting,
a project they run, or `expenses.manage`) decide what may be booked — asked
on create, on update and again at submit.

**Errors**, on every route in this section:

| Code | When |
|---|---|
| `403` | the coarse permission or the policy says no — including `This account is not linked to an employee record.` when a writing user has no employee row |
| `404` | unknown id; a receipt id that is not attached to the claim named in the path (`That receipt is not attached to this claim.`); bytes that have gone missing |
| `409` | an illegal transition, always with a `message` naming the state — `Only a draft claim can be edited. Cancel it and file a new one.` · `Only a draft claim can be submitted.` · `This claim is not waiting for approval.` · `Only a draft or a pending claim can be cancelled.` · `Receipts can only be changed while the claim is a draft.` |
| `422` | field validation with the `errors` map of §1.4: a prohibited field, a category ceiling (`Food claims are capped at 1000.00…` on `amount`), `You may only claim against a project or site you are assigned to.` on `site_id` / `project_id`, and `Attach at least one receipt — {Category} claims require evidence.` on `receipts` at submit. The 10-receipt ceiling answers `422` with a `message` rather than a field error |

**Filters**, all on `GET /expenses`, all narrowing rather than replacing:
`status` (comma-separated), `employee_id`, `project_id`, `site_id`,
`expense_category_id` (aliases `category`, `category_id`), and the
inclusive `expense_date` window `from` / `to` (aliases `date_from` /
`date_to`) — those are the spellings the controller reads. `page`,
`per_page`, `sort` (allow-listed: `expense_date` default, `amount`,
`status`, `created_at`), `direction` (default `desc`) and `search` / `q`
(on `description`) behave as §1.7–§1.8 say. The list itself is the usual
`PaginatedResponse` — `{items, meta}`.

> **The window is `from` / `to`.** `applyFilters()` reads `from` and `to`
> (aliases `date_from` / `date_to`) against `expense_date`; the Flutter side
> sends the same spelling. Nothing in the app offers a date range yet — the
> parameter exists for whoever builds one.

`GET /expenses/{expense}` loads `receipts` and `approvalRecords.actor`, so
`receipts` and `approval_chain` appear **on `show` and only there** — a list
of fifty rows must not pull fifty chains.

#### Summary

`GET /expenses/summary` — same gate, same row scope, same filters as the
index (they narrow the summary exactly as they narrow the list):

```json
{
    "from": "2026-09-01",
    "to": "2026-09-30",
    "by_status": {
        "draft":    {"count": 2, "amount": "410.00"},
        "pending":  {"count": 1, "amount": "250.00"},
        "approved": {"count": 3, "amount": "900.00"},
        "rejected": {"count": 1, "amount": "300.00"},
        "cancelled": {"count": 0, "amount": "0.00"}
    },
    "by_category": [{"expense_category_id": 3, "name": "Travel", "count": 4, "amount": "1150.00"}],
    "by_project":  [{"project_id": 1, "count": 5, "amount": "1560.00"}]
}
```

All five statuses are always present and zero-filled, so a screen prints
`"0.00"` for a state nobody reached rather than interpreting a missing key;
`by_category` and `by_project` may be empty arrays; every amount is a
decimal string. **This is a simple summary, not analytics** — the three
totals a queue screen asks for and the range they cover, deliberately with
no second aggregate and no trend behind them.

#### Expense categories

| Method | Path | Gate |
|---|---|---|
| GET | `/expense-categories` | `expenses.view` |

**A plain, unpaginated collection**: `data` is the array itself, not
`{items, meta}`. `?all=1` is the only query parameter honoured — without
it the active rows only, with it every row (so history still reads back a
retired category); no `page`, no `per_page`, no `sort`, no filters. Fields:
`id`, `name`, `code`, `description`, `status`, `is_active`,
`requires_receipt`, `maximum_amount`, `created_at`, `updated_at` — where
`maximum_amount: null` means **no ceiling**, which is not the same as `0`,
and `requires_receipt` is the rule the form has to show *before* the server
can be asked to accept the claim. Six rows are seeded, and there is
deliberately **no POST / PUT / DELETE**: a category is configuration an
operator seeds, not a resource this API writes.

#### Receipts

| Method | Path | Gate |
|---|---|---|
| POST | `/expenses/{expense}/receipts` | `expenses.view` + `storeReceipt` — your own claim, or anybody's with `expenses.manage` (the draft-only rule is the service's `409`, not this gate's `403`) |
| GET | `/expenses/{expense}/receipts/{receipt}` | `expenses.view` + `ExpensePolicy::viewReceipt` |
| DELETE | `/expenses/{expense}/receipts/{receipt}` | `expenses.view` + `deleteReceipt` — the same two ways in |

**Upload** is `multipart/form-data`, field name **`receipts`**, **1–6 per
request** on top of the service's **10 per claim** (over which it answers
`422` with a message). The rule set is `mimes:pdf,jpg,jpeg,png,webp` +
`mimetypes:application/pdf,image/jpeg,image/png,image/webp` — PDF, JPEG,
PNG, WebP — plus `CertificateContent`'s byte-level read, so a 3 KB of
garbage renamed `note.pdf` fails at the request on
`errors.receipts[0]`. Each file is capped at **5120 KB**
(`hrms.storage.expense_receipt_max_kilobytes`, `HRMS_EXPENSE_RECEIPT_MAX_KB`)
and lands in `hrms.storage.expense_receipt_directory`
(`HRMS_EXPENSE_RECEIPT_DIRECTORY`, default `expense-receipts`) as a
server-minted `expense-receipts/{expenseId}/{uuid}.{ext}` on the **private**
disk — the client's filename is kept as `original_name` data and never
addresses anything. The batch is one transaction: a request that fails
part-way leaves no file and no half-updated claim. The response is the
**whole `ExpenseResource`** again, not an acknowledgement, because the
screen it came from is drawing a `receipt_count`.

**Read** — `GET …/receipts/{receipt}` is the only route to the bytes: raw
stream, `Content-Type` from the stored extension,
`Content-Disposition: attachment; filename="receipt.pdf"` — a name minted
server-side, since `original_name` is client input with no business in a
response header — plus `Cache-Control: no-store, no-cache, must-revalidate,
max-age=0` and `X-Content-Type-Options: nosniff`. You cannot list receipts and
cannot fetch one for a claim you could not already read; an id that belongs
to a different claim answers `404`, and the state refusals
(`Receipts can only be changed while the claim is a draft.`) belong to
upload and delete, which answer `409`.

`ExpenseReceiptResource` is everything the API ever says about one:
`{id, expense_id, original_name, mime_type, size_bytes, is_image, is_pdf,
url, uploaded_by, created_at}`. **No storage path, ever** — and `url` is the
id-based route above (`/api/v1/expenses/{expense}/receipts/{receipt}`),
relative and deliberately unsigned, not a file URL to follow: a signed URL
is a bearer credential in a screenshot, a log line and a forwarded email.
`uploaded_by` is the **user** id.

The coarse gate is `expenses.view` rather than `expenses.receipts.view`
because the door has to admit the person who *filed* the receipt;
`viewReceipt` is the fine rule — your own claim's evidence, or
`expenses.receipts.view` **on top of** read access to that claim — being
shown a claim and being handed the invoice behind it are different
disclosures, so they are different permissions.

#### Workflow fields

- `current_approval_step` — the link the claim is waiting on, `null` as a
  draft and again once decided.
- `approval_chain` — the frozen `ApprovalRecord` sequence with each actor,
  **`show` only**.
- `submitted_at`, `approved_at`, `rejected_at`, `cancelled_at` — ISO-8601,
  each written once by the transition that owns it; `final_approved_by`
  names the last approver, `approval_workflow_id` the chain it runs on.
- `is_draft`, `is_open`, `summary` (one line for a list row:
  `Travel — AED 250.00 on 2026-09-28`), `receipt_count` / `receipts`, and
  the two category rules `requires_receipt` and `maximum_amount` read off
  the claim's own category.

Seeded **EXP-STD**: step 1 "Standard expense approval"
(`reporting_manager`), step 2 "Finance / HR" (`permission: expenses.manage`)
— the same two-step shape as leave and overtime, on
`ApprovalWorkflow::SUBJECT_EXPENSE`. A rejection stores its `remarks` on the
refused approval record rather than on the claim: *who said no, and why* is
a property of a step of the chain, which is where the timeline reads it
from, and Flutter's `reject(id, {required String remarks})` cannot send one
without it.

### 2.10b Client settings — (currency configuration ✅)

**`GET /client-settings`** → `200`:

```json
{
    "default_currency": "AED",
    "supported_currencies": ["AED"]
}
```

The two values a form needs before anybody can type into it, and nothing
else.

| Property | Detail |
|---|---|
| Authentication | bearer token only — **no `permission:`**, like `attendance/today` |
| Why no permission | the payload is non-secret (no payroll floors, no geofence radii, no timings) and *every* signed-in session needs it to open a claim form; gating it would show the wrong currency until somebody granted a right nobody asked for |
| `default_currency` | `system.currency`, upper-cased, reconciled against the list below — the default offered is always one `POST /expenses` will accept |
| `supported_currencies` | `system.supported_currencies`, upper-cased and de-duplicated: exactly the list `currency` is validated against. One entry ⇒ no choice is offered, several ⇒ a menu, empty ⇒ the company currency is returned and the membership rule is off |
| Allow-list, not a filter | the payload is built from named keys. A denylist is a list of things somebody remembered; sensitive rows were never candidates |
| Writes | none — there is no `PUT`. A currency is an operator's row, not something a phone edits |

Sourced once per *new* claim by Flutter's `core/config/client_settings.dart`.
Opening a draft does **not** fetch it: an edit keeps the currency the claim
was filed in, and nothing is ever converted.

### 2.11 Employee documents, expiry & onboarding — (Phase 10 ✅)

**16 routes.** The vocabulary is *file* rather than *document*: a document
type is a row of configuration, an employee document is one piece of
evidence, and onboarding is the question of which evidence is still
outstanding.

| Method | Path | Gate | Notes |
|---|---|---|---|
| GET | `/document-types` | `documents.view` | the catalogue a form offers; never a hard-coded list |
| GET | `/employee-documents` | `documents.view` | paginated, nine filters (below), **row-scoped** |
| POST | `/employee-documents` | `documents.create` | multipart; the caller may file their own or, with `documents.manage`, anybody's |
| GET | `/employee-documents/expiring` | `documents.expiry.view` | declared **before** `/{document}` — the Phase 7 ordering rule |
| GET | `/employee-documents/{document}` | policy | `documents.view` + row scope |
| PUT | `/employee-documents/{document}` | `documents.update` | replacing the file or a date **withdraws** the sign-off |
| DELETE | `/employee-documents/{document}` | `documents.delete` | only for a document that was never verified; archive is the normal exit |
| GET | `/employee-documents/{document}/file` | policy | the bytes, as a `StreamedResponse` with a sanitised download name |
| POST | `/employee-documents/{document}/verify` | `documents.verify` | records who and when; verifying an already-lapsed row answers `expired` |
| POST | `/employee-documents/{document}/reject` | `documents.verify` | `reason` is required |
| GET | `/employees/{employee}/bank-account` | **policy only** — no `permission:` | see below |
| PUT | `/employees/{employee}/bank-account` | **policy only** — no `permission:` | see below |
| GET | `/onboarding` | `onboarding.view` | directory over `employees`, left-joined with the record |
| GET | `/onboarding/{employee}` | `onboarding.view` | materialises the record on first read |
| PUT | `/onboarding/{employee}` | `onboarding.manage` | stage + notes |
| POST | `/onboarding/{employee}/complete` | `onboarding.manage` | **409 naming what is outstanding**, never a silent success |

#### `GET /employee-documents`

Nine filters, all of them questions a desk actually asks. Everything is
scoped first and filtered second, so a filter can never widen the row set:

| Parameter | Meaning |
|---|---|
| `employee_id`, `document_type_id` | exact |
| `status` | comma-separated (`pending,valid,expired,rejected,archived`); **omitted ⇒ everything except `archived`** — archive takes a row out of circulation, not out of the database, so asking for `status=archived` is how an operator goes and looks |
| `expired` | `1` — driven by the **date**, not the stored status: a document that lapsed an hour ago is expired whether or not the scheduler has run |
| `expiring_soon` | `1` — computed in SQL per type from `document_types.expiry_warning_days` (falling back to `hrms.expiry.default_warning_days`), never one global constant |
| `expiry_from`, `expiry_to` | a window on the expiry date |
| `search` | document number, file name, first or last name |
| `page`, `per_page` | §1.7 |

`GET /employee-documents/expiring?within={days}` takes one parameter,
clamped to `0…730`, and is the report behind the Expiry screen. It answers
for **everybody whose file the caller may already read** — which is exactly
why it needs its own grant rather than riding on `documents.view`: an
Employee passes `documents.view` and must still be refused here.

Every row carries `expiry_state` (`none` \| `valid` \| `expiring_soon` \| `expired`),
`warning_days` and `days_until_expiry` **computed on the server**. A client
that draws its own countdown would disagree with the nightly scan the first
time the two looked at different clocks, and the server's answer is the one
that gets acted on.

`file_url` is `/api/v1/employee-documents/{id}/file` — a route, not a
location. No payload in this API contains a disk path.

#### `POST /employee-documents`

`multipart/form-data`:

| Field | Rule |
|---|---|
| `employee_id` | required; **403** `You may not file a document for that employee.` unless the caller may manage documents or is filing their own |
| `document_type_id` | required, active type |
| `file` | required; **PDF / JPEG / PNG / WebP only** — MIME, extension *and* byte content all checked, `hrms.storage.document_max_kilobytes` (default 10240) ceiling, §4.6 |
| `document_number`, `issue_date`, `expiry_date` | required **only when the type says so** (§`GET /document-types`) |
| `notes` | optional |

Uploads always arrive `pending`. Identity is never a field: `status`,
`verified_at`, `verified_by` and `expiry_state` are refused rather than
ignored (422 naming each).

Changing the file, the number, the issue date or the expiry date resets the
row to `pending` and clears `verified_at`/`verified_by` — evidence that has
changed has not been looked at. Verify and reject both write `verified_by`;
only acceptance writes `verified_at`. A second decision on an already
settled row is **409**.

#### `GET /document-types`

```
GET /api/v1/document-types → 200
```

```json
{
    "success": true,
    "message": "Document types.",
    "data": [
        {
            "id": 1,
            "code": "PASSPORT",
            "name": "Passport",
            "description": "…",
            "requires_document_number": true,
            "requires_issue_date": true,
            "requires_expiry_date": true,
            "expiry_warning_days": 180,
            "status": "active"
        }
    ]
}
```

Nine seeded rows. The three requirement flags are what the form validates
against *and* what `StoreEmployeeDocumentRequest` enforces — one source, so a
screen that never showed a field cannot get past it either.

#### `GET /onboarding`

Filters: `status` (comma-separated), `incomplete=1` (nothing completed),
`missing={code}` (still outstanding), `search`. The list is a directory over
`employees`, so **somebody nobody has started appears as `draft` with
`exists: false`** rather than not appearing at all.

Each row carries `status` (`draft` \| `pending_documents` \| `hr_review` \| `completed`),
`exists`, `is_completed` and the four timestamps. `GET /onboarding/{employee}`
adds `checklist`: one item per requirement — `{code, label, kind, mandatory,
satisfied, state, employee_id}` where `state` is `satisfied` \| `pending_verification`
\| `rejected` \| `expired` \| `missing` — plus `missing` (the outstanding
mandatory codes) and `satisfied` / `total` counts.

Completion requires **every** mandatory item satisfied. Anything outstanding
answers **409** with a `message` naming each one, so the next action is on
the screen rather than in the user's imagination.

**Row scope.** `Visibility::onboardingEmployeesFor()` grants the whole
directory only with `onboarding.manage`; without it a caller sees
**themselves and nobody else**. `onboarding.view` on ten roles is therefore
not "everyone can browse the joiners" — it is "everyone may ask where they
stand".

#### `GET|PUT /employees/{employee}/bank-account`

The one pair in this API with **no `permission:` middleware at all** — the
policy is the only gate, and `EmployeePolicy::viewBankAccount` /
`updateBankAccount` decide per row. Bank details are `encrypted` at rest,
written through their own routes, and are **not present in `EmployeeResource`
or in any list**, so no endpoint can leak them by forgetting a `hidden()`.
The 422 from a bad field names the field and **never echoes the value**.

> **`APP_KEY` must not be rotated casually.** Every `employee_bank_accounts`
> column is an encrypted cast; rotating the key without re-encrypting the
> table turns every IBAN into unreadable bytes. See `docs/SECURITY.md` §4.

### 2.11a Training & assets

Documents and onboarding moved up into §2.11 in Phase 10; **this is what
remains from the original Phase 10 sketch and is not built.** Training and
assets were cut out of Phase 10's approved scope, and neither has a phase
number yet.

| Method | Path |
|---|---|
| GET/POST | `/training` … |
| POST | `/training/{id}/enroll` |
| GET/POST/PUT | `/assets` … |
| POST | `/assets/{id}/assign` |

### 2.12 Dashboards & Reports

| Method | Path |
|---|---|
| GET | `/dashboard/employee` |
| GET | `/dashboard/hr` |
| GET | `/dashboard/project-manager` |
| GET | `/dashboard/management` |
| GET | `/reports/attendance?type=csv&from=…&to=…` |
| GET | `/reports/{type}` | attendance, leave, payroll, overtime, manpower, movement, expenses… |

Export formats: `format=csv|xlsx|pdf`

### 2.13 Notifications

| Method | Path |
|---|---|
| GET | `/notifications` |
| POST | `/notifications/{id}/read` |
| GET | `/notification-preferences` |
| PUT | `/notification-preferences` |
| POST | `/devices` | register FCM token |
| DELETE | `/devices/{id}` | unregister on logout |

---

## 3. Rate Limiting

Limits live in `backend/config/rate_limiting.php` (which reads `.env`) and are
attached at the route as `throttle:{name}`. Never inline a number in a route.

| Scope | Limit | Key | Status |
|---|---|---|---|
| `POST /auth/login` | **5 / minute** | client IP | ✅ Phase 3 |
| `POST /auth/forgot-password`, `POST /auth/reset-password` | **5 / 15 minutes** | client IP | ✅ Phase 3 |
| `POST /attendance/check-in`, `POST /attendance/check-out`, `POST /site-visits/start`, `POST /site-visits/{id}/end` | **30 / minute** | authenticated user id | ✅ Phase 5 |
| Payroll / loans / certificate writes (36 routes) | none | — | ✅ Phase 8, **deliberately** — see below |
| Expense writes (13 routes) | none | — | ✅ Phase 9, **deliberately** — see below |
| Document, onboarding & bank-account writes (8 routes) | none | — | ✅ Phase 10, **deliberately** — see below |
| General API | 60 / minute | — | ⬜ Planned |
| Exports (PDF/Excel) | 10 / minute | — | ⬜ Phase 11 |

**Phase 6 added no new limiter, deliberately.** Leave, overtime, holiday and
timesheet writes are cheap, already behind `auth:sanctum` + a permission +
a policy, and rate-limiting them would punish a user retrying a flaky mobile
connection — which is exactly this product's normal condition. The one write
that could be abused, certificate upload, is bounded instead by the byte
ceiling and MIME rules in §4.

**Phase 7 added no limiter either** — a report is a record, not an expense;
the upload is bounded structurally (6 per request, 12 per report).

**Phase 8 added no limiter, deliberately — with one eye open.** None of the
36 payroll-family routes accepts a credential, a file or an unbounded body,
and each is behind `auth:sanctum` + a permission + a policy. The single
expensive call is `POST /payroll/process`, which prices a month across every
employee; it is bounded by *who may call it* (`payroll.process`: Super Admin,
HR Admin, Payroll Admin) and by the one-way ladder, so a repeat call costs
`updated: 0` rather than a second recalculation. A `process` limiter would be
the first thing to add if the general limiter (§row above) stays unwelcome —
it belongs in `config/rate_limiting.php`, not inline.

**Phase 9 added no limiter either, and for the same reason.** None of the 13
expense routes accepts a credential or an unbounded body — each sits behind
`auth:sanctum` + a permission + a policy, and the one write that takes bytes
is bounded structurally instead: 6 files per request, 10 per claim, 5120 KB
each (§4.5). A flood of claim writes is stopped by the permission middleware
before it reaches a query; a flood of receipts is stopped by the byte
ceiling.

**Phase 10 added no limiter either, deliberately.** The eight write routes —
five document, two onboarding, one bank account — are behind
`auth:sanctum` + a permission (except the bank pair, which is policy-only and
answers 403 for anyone but the row's own subject or a role holding
`employees.manage`) + a policy, and none accepts an unbounded body. The one
route that takes bytes, `POST /employee-documents`, is bounded structurally:
a single file, PDF/JPEG/PNG/WebP only, `hrms.storage.document_max_kilobytes`
default **10240 KB**, sniffed for its real content (§4.6) — and every upload
leaves a row behind, so abuse is a query rather than a guess. The nightly
scan is scheduled, not an endpoint, so there is nothing to hammer.

Exceeded → **HTTP 429** in the standard envelope, with a `Retry-After` header.

**Why attendance is keyed by user and not IP.** A site office is one NAT
address shared by every crew member checking in within the same minute; an
IP bucket would refuse the fourth person to reach the gate. Keying by user
throttles the machine actually retrying, keeps a whole crew able to clock
in together, and still bounds how fast one account can hammer the endpoints.
The read routes are not throttled at all: they are cheap, and a limiter on
`GET /attendance/today` would punish refreshes rather than abuse.

**Why client IP and not email.** Keying login by `email` would let anyone with
a connection flood one address and lock its real owner out of their own
account — a denial-of-service handed to the attacker for free. Keying by IP
throttles the machine actually doing the guessing. The trade-off is that a
shared NAT (an office, a café) shares one bucket; 5 attempts per minute is low
enough that this is felt rarely and high enough that it is not a support
burden.

**Client handling:** the Flutter client reads `Retry-After` into
`ApiException.retryAfter` and shows the wait on the sign-in form rather than
letting the user tap into the same wall repeatedly.

---

## 4. File Upload Rules

Phase 6 uploads exactly one kind of file: the **medical certificate** for a
sick-leave request. It is described in §4.2 below. Phase 5's check-in
**selfie** follows the same shape with stricter rules (§4.1), and Phase 7
adds the report photographs (§4.3). **Phase 8 uploads nothing** — its two
documents are rendered from a row on demand, described in §4.4. Phase 9
adds the **expense receipts**, described in §4.5, and Phase 10 the
**employee documents**, described in §4.6 — the largest of them: a file
nobody may read but its owner and HR.

- `multipart/form-data`, field name `selfie`, on `POST /attendance/check-in` only
- Max size **5120 KB** — `hrms.storage.selfie_max_kilobytes` (`HRMS_SELFIE_MAX_KB`)
- Images only (`jpg`, `jpeg`, `png`, `webp`), validated **four** ways:
  Laravel's declared-type rule, a `finfo` MIME sniff, the byte ceiling, and —
  new in this hardening pass — `App\Rules\ImageContent`, which parses the
  image header itself and refuses anything that does not decode, or that
  claims more than `hrms.storage.selfie_max_pixels` pixels (default
  16 777 216 = 4096 × 4096). The extension is not the arbiter and the
  client's `Content-Type` is not believed.

### 4.1 Sanitisation — what is stored is never what was sent

What reaches disk is produced by `SelfieSanitizer` (PHP **GD**, already
bundled with this PHP build — no extra Composer dependency):

1. the upload is decoded (`imagecreatefromstring`);
2. anything translucent is flattened onto white, so a PNG's alpha has
   nowhere surprising to go;
3. it is re-encoded as **JPEG at quality 85**
   (`hrms.storage.selfie_jpeg_quality`, `HRMS_SELFIE_JPEG_QUALITY`);
4. only those re-encoded bytes are written, to
   `attendance-selfies/{employeeId}/{uuid}.jpg`.

Re-encoding is the metadata removal: EXIF lives in JPEG APP segments that a
pixel decoder discards, so there is no GPS fix, no camera make or model, no
software string and no embedded thumbnail in anything the server stores.
The original filename is never read, and the original bytes are never
written — not even transiently.

| Client | Metadata protection |
|---|---|
| Flutter app | `SelfieCompressor` re-encodes before upload — a courtesy to a patchy site signal |
| **Any other client** | **`SelfieSanitizer` on the server — the security boundary** |

The two layers are independent on purpose. The app can be skipped, replaced
or modified; the server-side re-encode cannot be.

- Filenames are **never** taken from the request. The stored name is a
  generated UUID under `attendance-selfies/{employeeId}/{uuid}.jpg`, so no
  two devices can collide, no upload can address a path outside that
  directory, and no client-chosen extension survives. A failed check-in
  deletes the file it had already written.
- **Private storage** (`local` disk → `storage/app/private`), reachable only
  by `GET /api/v1/attendance/{attendance}/selfie` behind the `viewSelfie`
  policy and served `Cache-Control: no-store`. No public URL, no signed URL,
  no base64 in a JSON body, no way to enumerate the directory. **No response
  ever contains a filesystem path.**
- The check-in response carries `has_selfie`, never the bytes.
- Requires `Accept: application/json`

**Rejection responses.** All of the above are `422` with the message on
`errors.selfie[0]`, e.g. `"That file is not a readable image."` or
`"That image is too large to process. Take a smaller one."` — and no
attendance row and no file are created.

Nothing here feeds facial recognition. The photograph is evidence that a
person stood at that gate at that moment; it is opened by a human during a
dispute, never by a model, and never by someone who simply wants to look at
another employee's file. **Facial recognition is deliberately not built.**

### 4.2 Certificates — Phase 6 (sick leave)

- `multipart/form-data`, field name **`certificate`**, on
  `POST /api/v1/leave/{leaveRequest}/certificate` only
- Max size **5120 KB** — `hrms.storage.certificate_max_kilobytes`
  (`HRMS_CERTIFICATE_MAX_KB`)
- Types: `pdf, jpg, jpeg, png, webp`, validated **three** ways — `mimes`
  (declared extension), `mimetypes` (a `finfo` sniff), and
  `App\Rules\CertificateContent`, which reads the first bytes itself: a PDF
  must contain `%PDF-` within its first 1024 bytes (the spec allows leading
  junk) and an image must survive `getimagesize()`. A 3 MB blob renamed
  `note.pdf` is refused at the request with a message on
  `errors.certificate[0]`.
- **No re-encoding**, unlike the selfie: a scan is text a doctor signed, and
  transcoding a PDF or JPEG a second time risks degrading the very evidence
  it is. Validation is enough here because nothing in the response is ever
  rendered inline to a third party.
- The stored name is minted by the server —
  `leave-certificates/{employeeId}/{uuid}.{ext}` — and the client's filename
  is used only for the `Content-Disposition` on download, never for storage.
  A non-allow-listed extension falls back to `.pdf`.
- **Private storage** (`local` disk → `storage/app/private`). No public URL,
  no directory listing, no path in any JSON. Re-upload replaces the previous
  file in the same transaction, so a superseded certificate does not linger.
- Download: `GET /api/v1/leave/{leaveRequest}/certificate` — raw bytes,
  `Cache-Control: no-store`, authorized exactly like `uploadCertificate` on
  the request. `LeaveCertificateResource` sends `original_name` and
  `uploaded_at`, never the path.
- Requires `Accept: application/json` on the write; the read returns the
  file's own `Content-Type`.

**Rejection responses** are `422` with the message on `errors.certificate[0]`,
e.g. `"That file is not a PDF or an image a reader could open."` — and no
leave-request row and no file are changed.

### 4.3 Report photographs — Phase 7 (site activity & daily reports)

- `multipart/form-data`, field name **`photos`** (plural), on
  `POST /api/v1/site-activity-reports/{report}/photos` and
  `POST /api/v1/daily-site-reports/{report}/photos`
- **6 per request**, **12 per report**; more than 12 is refused at the
  request with a message on `errors.photos[0]`, and a batch that fails
  part-way is unwound so no report is ever left holding half a batch
- Max size **5120 KB** each — `hrms.storage.report_photo_max_kilobytes`
  (`HRMS_REPORT_PHOTO_MAX_KB`); directory
  `hrms.storage.report_photo_directory` (`HRMS_REPORT_PHOTO_DIRECTORY`,
  default `site-report-photos`)
- Types: `image/jpeg`, `image/png`, `image/webp` — declared extension
  *and* `finfo` sniff, the same allow-list the selfie uses
- **Re-encoded like the selfie, not kept as sent like the certificate.**
  Every frame goes through `SelfieSanitizer::sanitize()` via the shared
  `App\Services\Images\StoresPrivateImages` trait, which fails **closed**:
  an image that cannot be re-encoded is refused rather than stored
  untouched. This is the right trade here and wrong for §4.2 — a report
  photo is evidence of a site condition, not a document with a signature,
  and stripping the EXIF/GPS block the phone wrote is the point: the
  server records its own reading at submit, and a stale coordinate inside
  a JPEG would contradict it.
- One optional **`caption`** applies to the batch. The stored filename is
  minted by the server from the report — `site-report-photos/activity/{id}/…`
  or `site-report-photos/daily/{id}/…`; the client's filename is never used.
- **Private storage.** No public URL, no directory listing, no path and no
  `disk` key in any JSON. `SiteReportPhotoResource` /
  `DailySiteReportPhotoResource` expose `{id, caption, sort_order,
  mime_type, size_bytes, created_at}` and nothing else; images are never
  stored in the database.
- Reading the pixels: `GET …/photos/{photo}`, permission- and row-checked
  like any other read, with `Cache-Control: no-store` and
  `X-Content-Type-Options: nosniff`. The Flutter app fetches them through
  the authenticated client, which is why **no `Image.network` appears
  anywhere in the app** — a bare URL would need to be public, and there
  isn't one.
- A photo whose report is not a draft, or whose id belongs to a different
  report, answers `409` and `404` respectively — the id is checked
  *against the row*, not merely whether it exists.

### 4.4 Salary documents — Phase 8 (slips & certificates)

**Phase 8 uploads nothing at all**, which is the point rather than an
omission. Both of its documents are *rendered by the server from a row* and
streamed back:

- `GET /salary-slips/{payroll}/pdf` — the `payrolls` row, behind
  `salary_slips.view` **and** the same row scope as `GET /payroll/{id}`
- `GET /salary-certificate-requests/{id}/pdf` — behind
  `salary_certificates.view` **and** `SalaryCertificateRequestPolicy`; the
  call also runs `markGenerated()`, and only **after** the bytes are written
  to the response, so a failed render leaves the row `approved` (ask again)
  rather than `generated` with nothing behind it

Both: `application/pdf`, `Cache-Control: no-store`, `Pragma: no-cache`,
`X-Content-Type-Options: nosniff`, `Content-Disposition: inline` with a
**server-minted** filename (`safePdfFilename()`-shaped, never a value a
client sent). Same dompdf instance as §4.3's report PDF — no second PDF
package to install, secure or upgrade.

There is no `salary_slips` table, no `pdf_path` column and no file on disk
anywhere in this flow. The document does not exist until it is asked for, so
it cannot go stale behind a recalculation, cannot be listed, cannot be
guessed at by path, and needs no expiry policy.

### 4.5 Expense receipts — Phase 9

- `multipart/form-data`, field name **`receipts`** (plural), on
  `POST /api/v1/expenses/{expense}/receipts` only
- **6 per request**, **10 per claim** — the first is about one request's
  weight, the second about how much paper a single claim may end up
  carrying; over either, the request answers `422` with a message
  (`Attach up to six receipts at a time.` / `A claim can carry at most 10
  receipts.`), and a batch that fails part-way writes no file and no row
- Max size **5120 KB** each — `hrms.storage.expense_receipt_max_kilobytes`
  (`HRMS_EXPENSE_RECEIPT_MAX_KB`); directory
  `hrms.storage.expense_receipt_directory` (`HRMS_EXPENSE_RECEIPT_DIRECTORY`,
  default `expense-receipts`)
- Types: `pdf, jpg, jpeg, png, webp`, checked the same ways as §4.2's
  certificate — declared extension (`mimes`), `finfo` sniff (`mimetypes`),
  the byte ceiling, and `CertificateContent` reading the first bytes itself,
  so a renamed `.exe` fails at the request on `errors.receipts[0]`.
  `ExpenseReceiptStore` repeats the MIME and size checks before writing — a
  storage layer that trusts a validation layer it may one day stop sharing
  an author with is waiting for an upload bug
- **No re-encoding**, unlike §4.1 / §4.3: a receipt has to stay the document
  it was (a re-encoded PDF stops opening) and nothing here decodes pixels.
  What *is* stripped is the client's filename — the stored name is
  server-minted `expense-receipts/{expenseId}/{uuid}.{ext}`, so no upload can
  address a path outside that directory and no client-chosen extension
  survives
- **Private storage** (`local` disk → `storage/app/private`). No public URL,
  no signed URL, no directory listing, no path in any JSON:
  `ExpenseReceiptResource` emits metadata only (`id, expense_id,
  original_name, mime_type, size_bytes, is_image, is_pdf, url, uploaded_by,
  created_at`) and its `url` is the id-based route, not a file URL
- Download: `GET /api/v1/expenses/{expense}/receipts/{receipt}` — raw bytes
  behind `ExpensePolicy::viewReceipt` (your own claim, or
  `expenses.receipts.view` **plus** read access to that claim), `Content-Type`
  from the stored extension, `Content-Disposition: attachment` with a
  **server-minted** filename (never `original_name`), `Cache-Control:
  no-store`, `X-Content-Type-Options: nosniff`. `404` when the id is not
  attached to the claim named in the path or the bytes are gone

### 4.6 Employee documents — Phase 10

The most sensitive file this application holds: a passport, an Emirates ID,
a visa, a contract. It gets everything a receipt gets and one thing more —
**images are re-encoded**, so no photograph of a person's identity document
carries their GPS fix onto the disk.

- `multipart/form-data`, field name `file`, on `POST
  /api/v1/employee-documents` only. One file per request.
- Max size **10240 KB** — `hrms.storage.document_max_kilobytes`
  (`HRMS_DOCUMENT_MAX_KB`)
- Accepted: **PDF, JPEG, PNG, WebP**, validated **five** ways:
  1. a declared extension from `pdf, jpg, jpeg, png, webp` — the client's
     name is not believed, it is only used to reject an obvious stranger;
  2. `finfo` MIME sniff of the real bytes (`application/pdf`, `image/jpeg`,
     `image/png`, `image/webp`) — an HTTP client may label a part anything
     it likes, and trusting that header is the hole this check closes;
  3. the byte ceiling;
  4. content: a PDF must carry `%PDF-` inside its first kilobyte, and an
     image must actually decode (`SelfieSanitizer::probe()`) and fit the
     pixel budget;
  5. the same four, re-checked in `EmployeeDocumentStore` — a storage layer
     that trusts a validation layer it may one day stop sharing an author
     with is waiting for an upload bug.
- **The extension on disk follows the bytes, not the name.** A `.jpg` full
  of PDF is stored as `.pdf`.
- **Images are re-encoded through the attendance `SelfieSanitizer`**, the
  same decoder as the check-in selfie: EXIF GPS, camera body, software
  string and any embedded thumbnail cannot survive a trip through a pixel
  buffer, and what lands is whatever GD produced — which cannot also be a
  script. Nothing is downsampled for the sake of it: readability is not
  traded away for a smaller file.
- **PDFs are stored byte-for-byte.** A document that is not identical to
  the one filed stops being a document anybody can open, and stripping PDF
  segments would need a parser this application does not have. The defence
  is the `%PDF-` sniff, the uuid name, and serving it `nosniff`.
- **Unnameable**: `{employee-documents}/{employeeId}/{uuid}.{ext}` on the
  private `local` disk (`storage/app/private`). The client's filename — and
  anything path-like inside it — is discarded, so `../../evil.php` cannot be
  expressed even if validation were bypassed. Directory configurable with
  `HRMS_DOCUMENT_DIRECTORY`.
- **Unexposed**: no payload contains a path. `EmployeeDocumentResource`
  reports `has_file`, `original_name`, `mime_type`, `file_size` and
  `file_url`, and `file_url` is `/api/v1/employee-documents/{id}/file`.
- **Download**: `GET /api/v1/employee-documents/{document}/file` — raw bytes
  behind `EmployeeDocumentPolicy` (your own, or `documents.view` +
  `documents.manage` for somebody else's), a `StreamedResponse` with
  `Content-Type` from the stored extension, `Cache-Control: no-store`,
  `X-Content-Type-Options: nosniff`, and `Content-Disposition: attachment`
  under a **server-minted** name: every byte outside `[A-Za-z0-9 _-]` is
  dropped from the client's `original_name` (which removes CR, LF and the
  quote that would close the disposition attribute), length capped, empty ⇒
  `document.{ext}`. `404` when the row has no file or the bytes are gone.
- **Deleting is not destroying.** The store's `remove()` runs when a
  document is *replaced* so the superseded bytes do not linger unpointed-at;
  archive never calls it. `DELETE /employee-documents/{document}` exists only
  for a row that was never verified — a verified document has to be archived,
  and archiving takes it out of the active list while leaving the row and its
  file on the employment file.

---

## 5. Offline Sync Contract

There is **no batch endpoint**. Each queued event is replayed through the
same route that would have carried it live, which is what keeps one code
path responsible for the rules:

```
POST /api/v1/attendance/check-in        (multipart)
    client_event_id = 3d6f1a2c-…        generated BEFORE the first attempt
    source          = offline
    site_id, latitude, longitude, accuracy, selfie, device_reference
```

`check-out` and `site-visits/start` behave the same way; `site-visits/end`
carries its own `client_event_id` against the row's `end_client_event_id`.

**Server behaviour, in order:**

1. A `client_event_id` is looked up in the unique index that belongs to the
   action (`attendances.client_event_id`,
   `attendances.check_out_client_event_id`, `site_visits.client_event_id`,
   `site_visits.end_client_event_id`). Found → the **original row** comes
   back and nothing else is evaluated. Check-in replays answer **201**,
   check-out and visit replays answer **200**.
2. Not found → the request is validated as though it had arrived live:
   active site assignment, geofence against the *provided* coordinates,
   accuracy ceiling, selfie rules, duplicate-day and open-row checks.
3. Accept → the row is inserted inside a transaction; the unique index makes
   two simultaneous replays collapse to one row, and the loser re-reads and
   returns the winner's answer.
4. Reject → the standard error envelope. The client marks the event
   `failed`, keeps it (and its selfie file), shows the reason, and lets the
   person retry or drop it.

Distances, minutes, employee, date and status are still derived server-side
on a replay — an offline event is a claim about *where*, never about *when
the day was*.

> **Known characteristic:** `attendance_date` is taken from the server clock
> at the moment the row is written. An event captured at 23:55 and synced
> after midnight lands on the following day. The queue is ordered oldest
> first, so a full day's backlog replays in the order it happened, but a
> day that straddles midnight is not stitched back together.

**Client behaviour:** every attempt gets a UUID before the request goes
out; a network failure (`statusCode == 0`) queues the event with
`sync_status = pending_sync`, the compressed selfie written to a local file
and the event JSON to `SharedPreferences`. Any other failure — 403, 409,
422 — is shown, not queued, because it will not get better by retrying.
Syncing is manual ("Sync now"), oldest first, and stops at the first 0 or
401.

---

## 6. Implementation Status

| Area | Status |
|---|---|
| Conventions (this document) | ✅ Defined |
| §2.1 Authentication | ✅ Phase 3 |
| §2.2 Departments / Designations / Employees | ✅ Phase 4 |
| §2.3 Projects / Sites / Assignments | ✅ Phase 4 |
| §2.4 Attendance / §2.5 Site Visits & Movement | ✅ Phase 5 |
| §2.7 Timesheets / Overtime / Approval workflows | ✅ Phase 6 |
| §2.8 Leave / §2.8a Certificates & LOP / §2.9 Holidays | ✅ Phase 6 |
| §2.6 Site Activity & Daily Reports | ✅ **Phase 7** |
| §2.10 Payroll / Loans / Salary documents | ✅ **Phase 8** |
| §2.10a Expenses & Receipts | ✅ **Phase 9** |
| §2.11 Employee documents, expiry & onboarding | ✅ **Phase 10** |
| §2.11a Training & assets | ⬜ **out of Phase 10's approved scope — unscheduled** |
| §2.12–§2.13 Everything else | ⬜ Phases 11–12 |
| Rate limiting — auth routes | ✅ Phase 3 |
| Rate limiting — attendance writes | ✅ Phase 5 |
| Rate limiting — leave/timesheet/overtime/holiday writes | **deliberately none** — see §3 |
| Rate limiting — site-report writes | **deliberately none** — see §3 |
| Rate limiting — payroll / loan / certificate writes | **deliberately none** — see §3 |
| Rate limiting — expense writes | **deliberately none** — see §3 |
| Rate limiting — document / onboarding / bank writes | **deliberately none** — see §3 |
| Rate limiting — remaining scopes | ⬜ As their modules land |
| §4.1 File upload rules (selfie) | ✅ Phase 5, **sanitised server-side since the post-Phase 5 hardening pass** |
| §4.2 File upload rules (medical certificate) | ✅ Phase 6 |
| §4.3 File upload rules (report photographs) | ✅ **Phase 7** |
| §4.4 Salary documents (rendered, never uploaded) | ✅ **Phase 8** |
| §4.5 File upload rules (expense receipts) | ✅ **Phase 9** |
| §4.6 File upload rules (employee documents) | ✅ **Phase 10** |

Backend proof: `php artisan test` → **553 passed (3674 assertions)**; **170
route definitions** under `api/*` (175 registered) — Phase 6 added 37,
Phase 7 added 18, Phase 8 added 36, Phase 9 added 13, the currency pass
added 1, **Phase 10 added 16**. Flutter proof: `dart format .` clean (246
files), `flutter analyze` clean, `flutter test` → **551 passed**.

> To explore a running API later, use Laravel's generated OpenAPI/Swagger UI or
> a tool such as Postman.
