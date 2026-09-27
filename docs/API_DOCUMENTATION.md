# API Documentation

> **Status:** Phases 1–4. §1 conventions, §2.1 authentication (Phase 3) and
> §2.2–§2.3 the organisation modules (Phase 4) are **live**. Everything from
> §2.4 onward is the contract the remaining phases build against.

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
> §2.7 (timesheets + overtime), §2.8 (leave), §2.9 (holidays) and
> §2.7's approval-workflows in **Phase 6**. Everything still pending is listed
> here as the contract to build against.

**Two gates, both live on every Phase 4, Phase 5 and Phase 6 route** (the three
exceptions are named in §2.7 / §2.9 — holiday reads, and the certificate
read/write, which are policy-only on purpose):

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

### 2.6 Site Activity & Daily Reports

| Method | Path |
|---|---|
| GET/POST/PUT | `/site-reports` … |
| POST | `/site-reports/{id}/photos` | multiple images |
| GET/POST | `/daily-reports` … |
| GET | `/daily-reports/{id}/pdf` | generated PDF |

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
**No payroll figure is computed from `lop_days` in this phase.**

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

### 2.10 Payroll & Finance

| Method | Path | Permission |
|---|---|---|
| GET | `/payroll?month=2026-09` | `payroll.view` |
| POST | `/payroll/process` | `payroll.manage` |
| GET | `/salary-slips` | Own only, unless `payroll.view` |
| GET | `/salary-slips/{id}/pdf` | Signed / authorized |
| GET/POST | `/salary-certificates` | request + generate |
| GET/POST/PUT | `/loans` … | `payroll.manage` |
| GET/POST/PUT | `/expenses` … | workflow approval |
| POST | `/expenses/{id}/approve` | `expenses.approve` |

### 2.11 Documents, Training, Assets

| Method | Path |
|---|---|
| GET/POST/PUT/DELETE | `/documents` … |
| GET | `/documents/expiring?days=30` |
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
| General API | 60 / minute | — | ⬜ Planned |
| Exports (PDF/Excel) | 10 / minute | — | ⬜ Phase 11 |

**Phase 6 added no new limiter, deliberately.** Leave, overtime, holiday and
timesheet writes are cheap, already behind `auth:sanctum` + a permission +
a policy, and rate-limiting them would punish a user retrying a flaky mobile
connection — which is exactly this product's normal condition. The one write
that could be abused, certificate upload, is bounded instead by the byte
ceiling and MIME rules in §4.

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
**selfie** follows the same shape with stricter rules (§4.1).

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
| §2.6, §2.10–§2.13 Everything else | ⬜ Phases 7–12 |
| Rate limiting — auth routes | ✅ Phase 3 |
| Rate limiting — attendance writes | ✅ Phase 5 |
| Rate limiting — leave/timesheet/overtime/holiday writes | **deliberately none** — see §3 |
| Rate limiting — remaining scopes | ⬜ As their modules land |
| §4.1 File upload rules (selfie) | ✅ Phase 5, **sanitised server-side since the post-Phase 5 hardening pass** |
| §4.2 File upload rules (medical certificate) | ✅ Phase 6 |
| §4 File upload rules (employee documents) | ⬜ Phase 10 |

Backend proof: `php artisan test` → **383 passed (2017 assertions)**; **86 routes**
under `api/*` (Phase 6 added 37). Flutter proof: `dart format .` clean,
`flutter analyze` clean, `flutter test` → **215 passed**.

> To explore a running API later, use Laravel's generated OpenAPI/Swagger UI or
> a tool such as Postman.
