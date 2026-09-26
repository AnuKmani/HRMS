# Security

> **Status:** Phase 1 — Foundation. This document defines the security requirements the
> system must meet. Individual controls are implemented in later phases and marked below.

---

## 1. Threat Model — what we are protecting

| Asset | Threat | Impact |
|---|---|---|
| Auth tokens | Theft from device or logs | Full account takeover |
| Passwords | Plaintext storage / reuse | Credential compromise |
| Selfies | Public exposure | Privacy violation |
| Passports, Emirates IDs, visas | Public exposure | Identity theft, legal exposure |
| Salary slips, contracts | Unauthorized access | Financial/HR confidentiality breach |
| Attendance GPS data | Falsification | Payroll fraud |
| Geofence check | Client-side bypass | Attendance fraud |
| `.env`, keystores, Firebase keys | Commit to Git | Infrastructure compromise |
| Database | SQL injection | Data exfiltration / destruction |

---

## 2. Authentication

| Requirement | Implementation | Status |
|---|---|---|
| Token-based auth | **Laravel Sanctum** personal access tokens | ⬜ Phase 3 |
| One token per device | Separate token rows, individually revocable | ⬜ Phase 3 |
| Logout revokes token | `token->delete()` on the current device only | ⬜ Phase 3 |
| Password hashing | `bcrypt` (cost 12) / `argon2id` — never plaintext | ⬜ Phase 3 |
| Forgot / reset password | Signed, expiring reset link | ⬜ Phase 3 |
| Change password | Requires current password | ⬜ Phase 3 |
| Session listing | User can view and revoke other devices | ⬜ Phase 3 |
| Rate limiting on login | `6/min/IP` → HTTP 429 | ⬜ Phase 3 |
| Lockout after failures | Throttling + audit log | ⬜ Phase 3 |

**Never stored:** passwords in plain text, tokens in logs, tokens in `shared_preferences`.

**Flutter side:** token lives in `flutter_secure_storage` (iOS Keychain / Android
Keystore). It is never written to plain preferences, never logged, never displayed.

---

## 3. Authorization — RBAC + Policies

Two layers, both mandatory:

### 3.1 Permission layer (`spatie/laravel-permission`)

10 roles with granular permissions:

```
employees.view       employees.create      employees.update      employees.delete
attendance.view      attendance.manage
leave.view           leave.approve
payroll.view         payroll.manage
projects.manage      sites.manage
timesheet.approve    overtime.approve
expenses.approve     documents.manage
reports.view         audit.view
notifications.manage settings.manage
```

### 3.2 Resource layer (Policies)

Permissions answer *"may this role do X?"*. Policies answer *"may this user do X to
**this** record?"*.

| Rule | Enforced by |
|---|---|
| An employee may read **their own** attendance | `AttendancePolicy::view` |
| An employee may **not** read a colleague's salary | `SalarySlipPolicy::view` |
| An employee may **not** access HR endpoints | Permission middleware → `403` |
| HR may override an attendance record | `AttendancePolicy::override` + audit log |

> **Hiding a button in Flutter is not authorization.** Every rule must also be enforced
> in Laravel. The Flutter UI hides controls only for usability.

**Status:** ⬜ Phase 3–4

---

## 4. File Storage Security

### 4.1 Private storage

Selfies, passports, Emirates IDs, visas, employment contracts, medical documents and
salary documents are stored on a **private disk** — never in `public/`.

```
backend/storage/app/private/
├── selfies/
├── documents/employees/
├── documents/payroll/
└── reports/
```

### 4.2 Access control

Files are served only through an **authorized, expiring URL** — either a signed route
or a temporary URL, and only after a Policy check.

```
GET /api/v1/files/{id}   →  Policy check  →  temporary signed URL  →  file stream
```

No file is ever reachable by guessing a path.

### 4.3 Upload validation (server-side, always)

| Check | Rule |
|---|---|
| MIME type | Detected from content, **not** the client-supplied header |
| Extension | Must be in the allow-list and match the MIME |
| Size | Selfies ≤ 2 MB; documents ≤ 10 MB (configurable) |
| Filename | **Never trusted** — server generates the stored name |
| Image re-encode | Strips EXIF/metadata (including GPS) from uploaded photos |
| Depth | Reject malformed images designed to exploit parsers |

`jpg`, `jpeg`, `png`, `pdf` only, unless explicitly extended.

**Status:** ⬜ Phase 5 (selfie), Phase 10 (documents)

---

## 5. Transport Security

- Production runs **HTTPS only**; HSTS enabled
- No hard-coded `http://` anywhere in the app
- Local development may use `http://127.0.0.1` (geolocation requires `localhost` or HTTPS)
- API is HTTPS-ready — no application change needed to enable TLS

**Status:** ⬜ Deployment phase

---

## 6. Input Validation & Injection

| Threat | Control |
|---|---|
| SQL injection | Eloquent / query builder with parameter binding — **no raw string SQL** |
| XSS | JSON API (no HTML rendering); escape anything later rendered |
| Mass assignment | `$fillable` / `$guarded` on every model |
| Over-posting | FormRequest validation on every write endpoint |
| CSRF | Stateless token API — CSRF applies only if session auth is used on web routes |

**Status:** ⬜ Phase 3 onward — validation is mandatory on every endpoint

---

## 7. Rate Limiting

| Scope | Limit | On breach |
|---|---|---|
| `POST /auth/login` | 6 / min / IP | `429` + `Retry-After` |
| `POST /auth/forgot-password` | 3 / min | `429` |
| Attendance endpoints | 30 / min | `429` |
| General API | 60 / min | `429` |
| Exports (PDF/Excel) | 10 / min | `429` |

**Status:** ⬜ Phase 3

---

## 8. Audit Logging

`spatie/laravel-activitylog` records who did what, with old and new values.

**Audited operations:**

| Module | Operations |
|---|---|
| Attendance | override, modification, geofence rejection |
| Leave | approval, rejection, LOP conversion |
| Payroll | processing, any salary modification |
| Employees | create, update, status change |
| Documents | upload, delete, expiry-driven status change |
| Sites | assignment create/end, geofence radius change |
| Overtime | approval / rejection |
| Expenses | approval / rejection |
| Settings | any configuration change |
| Auth | login success/failure, password change, token revoke |

**Recorded fields:** user, action, module, record, old values, new values, timestamp,
IP address, user-agent.

**Status:** ⬜ Phase 5 onward

---

## 9. Secrets Management

### 9.1 Never committed

```
.env
*.jks  *.keystore  *.pem  *.key  *.p12
google-services.json
GoogleService-Info.plist
*firebase-adminsdk*.json
database passwords, API keys, private certificates
```

All blocked by the root `.gitignore`.

### 9.2 Rules

- `.env.example` is committed with **placeholder values only**
- Real secrets exist only on the developer machine and the production server
- No secret in any source file, comment, test fixture or documentation
- Production uses environment variables, never committed config
- Firebase Admin SDK credentials live on the **server**, never inside the mobile app
- The mobile app contains only the public Firebase config (safe to ship in the APK)

**Status:** ✅ `.gitignore` in place · ⬜ `.env.example` in Phase 1a Laravel step

---

## 10. Device & App Security (Flutter)

| Control | Detail |
|---|---|
| Token storage | Keychain / Keystore, never plain storage |
| Screenshotting of sensitive screens | Consider `flutter_windowsecure` for salary screens |
| Certificate pinning | Optional, for high-security deployments |
| Debug logging | Disabled in release builds |
| Root/jailbreak detection | Optional, warn-only (not a substitute for server-side auth) |
| App permissions | Request only at the moment of use, with clear explanation |

---

## 11. Privacy & Location

**Location is collected only for specific business actions:**

- Attendance check-in / check-out
- Site visits
- Site activity reports

**Explicitly NOT implemented:**

- ❌ Continuous background GPS tracking
- ❌ Location monitoring when the app is idle
- ❌ Facial recognition on selfies

**Employee-facing transparency:**

- Permissions requested contextually, never all at once on first launch
- Plain-language explanation of *why* location and camera are needed
- Graceful handling of denial — the employee keeps using the app, and the limitation is
  explained clearly (with an HR contact for exceptions)

**Data minimisation:** GPS accuracy is stored for attendance validation, then retained
only as long as the business/retention policy requires.

**Status:** ⬜ Phase 5

---

## 12. Password Policy

| Rule | Value |
|---|---|
| Minimum length | 10 characters |
| Complexity | At least one letter and one number |
| Hashing | `bcrypt` cost 12 (or `argon2id`) |
| History | Prevent reuse of last 5 |
| Reset link expiry | Configurable, default 60 minutes |
| Delivery | One-time signed token, invalidated on use |

**Status:** ⬜ Phase 3

---

## 13. Security Checklist by Phase

| Phase | Controls delivered |
|---|---|
| 1 | `.gitignore` secret blocking, private-storage plan |
| 3 | Sanctum auth, rate limiting, password policy, login audit |
| 4 | Policies on employees/projects/sites, form requests |
| 5 | Geofence server validation, selfie private storage + upload validation, attendance audit |
| 6 | Offline sync idempotency, server re-validation of offline GPS |
| 9 | Leave approval audit, LOP conversion audit |
| 10 | Document private storage, signed URLs, expiry jobs |
| 11 | Salary access control (own-only), payroll audit |
| 13 | Full security audit, penetration-style test pass, deployment hardening |

---

## 14. Reporting a Security Issue

Do not open a public issue for a security vulnerability. Contact the maintainer
privately with reproduction steps.
