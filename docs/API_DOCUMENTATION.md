# API Documentation

> **Status:** Phase 1 — Foundation. **No endpoints are implemented yet.**
> This document defines the conventions every endpoint will follow, plus the planned
> route list. Concrete request/response examples will be added as each phase ships.

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
        "meta":   { "current_page": 1, "per_page": 15, "total": 132, "last_page": 9 },
        "links":  { "next": "...", "prev": null }
    }
}
```

### 1.8 Filtering

```
GET /api/v1/employees?search=ahmed&department_id=3&status=active&sort=created_at&direction=desc
GET /api/v1/attendance?from=2026-09-01&to=2026-09-30&site_id=4&status=late
```

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

> **Implemented:** §2.1 (Phase 3). Everything from §2.2 onward arrives with
> Phases 4–12 and is listed here as the contract to build against.

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

### 2.2 Employees

| Method | Path | Permission |
|---|---|---|
| GET | `/employees` | `employees.view` |
| POST | `/employees` | `employees.create` |
| GET | `/employees/{id}` | `employees.view` |
| PUT | `/employees/{id}` | `employees.update` |
| DELETE | `/employees/{id}` | `employees.delete` |
| GET | `/employees/{id}/documents` | `employees.view` |
| POST | `/employees/{id}/onboarding` | `employees.update` |

### 2.3 Projects & Sites

| Method | Path | Permission |
|---|---|---|
| GET/POST/PUT/DELETE | `/projects` … | `projects.manage` |
| GET/POST/PUT/DELETE | `/sites` … | `sites.manage` |
| GET | `/sites/{id}/members` | `sites.manage` |
| GET/POST | `/assignments` | `sites.manage` |
| PUT | `/assignments/{id}/end` | `sites.manage` — ends an assignment, never overwrites |

### 2.4 Attendance

| Method | Path | Notes |
|---|---|---|
| POST | `/attendance/check-in` | GPS + selfie + `client_uuid`; **server validates geofence** |
| POST | `/attendance/check-out` | GPS + optional selfie |
| GET | `/attendance/today` | Current user's today |
| GET | `/attendance` | List with filters (HR) |
| GET | `/attendance/{id}` | Detail with selfie (signed URL) |
| POST | `/attendance/{id}/override` | HR only — requires reason, writes audit log |
| POST | `/attendance/sync` | Batch offline sync with idempotency keys |

**Check-in request**
```json
{
    "client_uuid": "9f1c2b7e-...",
    "site_id": 4,
    "latitude": 25.204849,
    "longitude": 55.270783,
    "accuracy": 12.5,
    "captured_at": "2026-09-26T08:05:12+04:00",
    "selfie": "<file>"
}
```

**Response — inside geofence**
```json
{
    "success": true,
    "message": "Checked in successfully.",
    "data": {
        "attendance_id": 1042,
        "check_in_at": "2026-09-26 08:05:12",
        "site": "Project A - Site 1",
        "distance_metres": 34.2,
        "status": "present"
    }
}
```

**Response — outside geofence (HTTP 422)**
```json
{
    "success": false,
    "message": "You are outside the allowed area for this site.",
    "errors": {
        "geofence": ["You are 482 m from the site. Maximum allowed is 100 m."]
    }
}
```

### 2.5 Site Visits

| Method | Path | Notes |
|---|---|---|
| POST | `/site-visits/start` | GPS + site + purpose |
| POST | `/site-visits/{id}/end` | GPS + optional selfie |
| GET | `/site-visits/timeline?date=` | Daily movement timeline |
| GET | `/site-visits` | List with filters (manager/HR) |

### 2.6 Site Activity & Daily Reports

| Method | Path |
|---|---|
| GET/POST/PUT | `/site-reports` … |
| POST | `/site-reports/{id}/photos` | multiple images |
| GET/POST | `/daily-reports` … |
| GET | `/daily-reports/{id}/pdf` | generated PDF |

### 2.7 Shifts, Timesheets, Overtime

| Method | Path | Permission |
|---|---|---|
| GET/POST/PUT | `/shifts` … | `attendance.manage` |
| GET/POST | `/timesheets` … | `timesheet.approve` |
| GET/POST | `/overtime` … | `overtime.approve` |
| POST | `/overtime/{id}/approve` | multi-step approval |
| POST | `/overtime/{id}/reject` | requires reason |

### 2.8 Leave

| Method | Path | Notes |
|---|---|---|
| GET | `/leave/types` | Configured types + entitlements |
| GET | `/leave/balance?year=2026` | Entitlement / used / pending / remaining |
| GET | `/leave/requests` | With filters |
| POST | `/leave/requests` | Validates balance + overlap |
| POST | `/leave/requests/{id}/approve` | Permission + workflow |
| POST | `/leave/requests/{id}/reject` | Requires reason |
| POST | `/leave/requests/{id}/cancel` | By requester |
| POST | `/leave/requests/{id}/document` | Medical certificate upload |

### 2.9 Payroll & Finance

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

### 2.10 Documents, Training, Assets

| Method | Path |
|---|---|
| GET/POST/PUT/DELETE | `/documents` … |
| GET | `/documents/expiring?days=30` |
| GET/POST | `/training` … |
| POST | `/training/{id}/enroll` |
| GET/POST/PUT | `/assets` … |
| POST | `/assets/{id}/assign` |

### 2.11 Dashboards & Reports

| Method | Path |
|---|---|
| GET | `/dashboard/employee` |
| GET | `/dashboard/hr` |
| GET | `/dashboard/project-manager` |
| GET | `/dashboard/management` |
| GET | `/reports/attendance?type=csv&from=…&to=…` |
| GET | `/reports/{type}` | attendance, leave, payroll, overtime, manpower, movement, expenses… |

Export formats: `format=csv|xlsx|pdf`

### 2.12 Notifications

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
| Attendance endpoints | 30 / minute | — | ⬜ Phase 5 |
| General API | 60 / minute | — | ⬜ Planned |
| Exports (PDF/Excel) | 10 / minute | — | ⬜ Phase 12 |

Exceeded → **HTTP 429** in the standard envelope, with a `Retry-After` header.

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

- `multipart/form-data`
- Max selfie / photo size: **2 MB** (compressed client-side to ~200 KB before upload)
- Allowed types: `jpg`, `jpeg`, `png`, `pdf` — validated by **MIME, extension and size** server-side
- Filenames are **never** trusted; the server generates the stored name
- Files land in **private storage**, returned only via signed/expiring URLs
- Requires `Accept: application/json`

---

## 5. Offline Sync Contract

```
POST /attendance/sync
Body: { "actions": [ { "client_uuid", "type", "payload", ... } ] }
```

Server behaviour for each action:

1. Look up `client_uuid` — if already processed, **return the original result**
   (idempotent; no duplicate attendance)
2. Re-validate geofence using the *provided* GPS — offline GPS is stored, never trusted
3. Validate timing against a configurable tolerance window
4. Accept → return `attendance_id`, client marks `synced`
5. Reject → return reason, client marks `rejected` and shows it to the employee

---

## 6. Implementation Status

| Area | Status |
|---|---|
| Conventions (this document) | ✅ Defined |
| §2.1 Authentication | ✅ Phase 3 |
| §2.2–§2.12 Everything else | ⬜ Phase 4 onward |
| Rate limiting — auth routes | ✅ Phase 3 |
| Rate limiting — remaining scopes | ⬜ As their modules land |

Backend proof: `php artisan test` → **120 passed (517 assertions)**, covering
`AuthenticationTest`, `AuthorizationTest`, `PasswordResetTest` and
`ApiErrorHandlingTest`.

> To explore a running API later, use Laravel's generated OpenAPI/Swagger UI or
> a tool such as Postman.
