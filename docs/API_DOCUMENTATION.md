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
    "errors": null
}
```

### 1.6 Status codes

| Code | Meaning | Flutter behaviour |
|---|---|---|
| `200` | OK | Parse `data` |
| `201` | Created | Parse `data`, refresh list |
| `401` | Token expired / invalid | Attempt refresh → else force re-login |
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
```

Tokens are issued at login and stored in **secure storage** (Keychain / Keystore) —
never in plain preferences.

---

## 2. Planned Endpoints

### 2.1 Authentication

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login` | Returns token + user profile + permissions |
| POST | `/auth/logout` | Revokes current token |
| GET | `/auth/me` | Current user, roles, permissions |
| POST | `/auth/forgot-password` | Sends reset link |
| POST | `/auth/reset-password` | Token + new password |
| POST | `/auth/change-password` | Requires current password |
| GET | `/auth/sessions` | List active device tokens |
| DELETE | `/auth/sessions/{id}` | Revoke a specific device |

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

| Scope | Limit |
|---|---|
| `POST /auth/login` | 6 / minute / IP |
| `POST /auth/forgot-password` | 3 / minute |
| Attendance endpoints | 30 / minute |
| General API | 60 / minute |
| Exports (PDF/Excel) | 10 / minute |

Exceeded → **HTTP 429** with `Retry-After` header.

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
| All endpoints | ⬜ Not implemented — Phase 3 onward |

> To explore a running API later, use Laravel's generated OpenAPI/Swagger UI or
> a tool such as Postman.
