# Security

> **Status:** Phase 4 — authentication, rate limiting, the password policy, the
> permission layer and now **row-level policies** for the organisation modules
> are live and tested. File storage, transport hardening and audit logging
> remain phased ahead (§4, §5, §8). Individual controls are marked with their
> phase below.

---

## 1. Threat Model — what we are protecting

| Asset | Threat | Impact |
|---|---|---|
| Auth tokens | Theft from device or logs | Full account takeover |
| Passwords | Plaintext storage / reuse | Credential compromise |
| Selfies | Public exposure | Privacy violation |
| Passports, Emirates IDs, visas | Public exposure | Identity theft, legal exposure |
| Salary slips, contracts | Unauthorized access | Financial/HR confidentiality breach |
| Employee `salary` field | Read by anyone who may open the roster | Payroll confidentiality breach — gated by `employees.salary.view`, absent from the list projection entirely |
| Attendance GPS data | Falsification | Payroll fraud |
| Geofence check | Client-side bypass | Attendance fraud |
| `.env`, keystores, Firebase keys | Commit to Git | Infrastructure compromise |
| Database | SQL injection | Data exfiltration / destruction |

---

## 2. Authentication

| Requirement | Implementation | Status |
|---|---|---|
| Token-based auth | **Laravel Sanctum** personal access tokens, `auth:sanctum` on every route | ✅ Phase 3 |
| One token per device | Token rows named by `device_name`; login deletes an existing row of that name before creating the next | ✅ Phase 3 |
| Logout revokes token | `$token->delete()` on the token **this request presented** — other devices untouched | ✅ Phase 3 |
| Password hashing | `bcrypt`, `BCRYPT_ROUNDS=12` (4 in tests for speed) — never plaintext | ✅ Phase 3 |
| No account enumeration | One identical `401` for unknown address and wrong password; `forgot-password` has no `exists:` rule | ✅ Phase 3 |
| Deactivated accounts | `status` checked **after** the password verifies → `401` | ✅ Phase 3 |
| Forgot / reset password | Signed, expiring, single-use link — **answers 501 until a mailer can really deliver** | ✅ Built · ⬜ Delivery gated |
| Change password | Requires current password; deletes every *other* token | ✅ Phase 3 |
| Session listing | `GET /auth/sessions` + `DELETE /auth/sessions/{id}` (own account only, 404 otherwise) | ✅ Phase 3 |
| Rate limiting on login | `5 / min / IP` → `429` + `Retry-After` | ✅ Phase 3 |
| Lockout after failures | **Deliberately not implemented** — see below | ✅ Decided |

**Why there is no account lockout.** A lockout is a denial-of-service anyone
can aim at a known address: flood one email and its owner cannot sign in.
Throttling per IP slows the machine doing the guessing without giving an
attacker a lever over someone else's account. Audit logging of login attempts
(§8) is the complementary control and arrives with Phase 5.

**Why password reset answers 501.** `MAIL_MAILER=log` writes the link to a
file nobody reads. Returning `200` would tell a user a reset email is on its
way when none is — worse than an honest "not available". Set
`PASSWORD_RESET_ENABLED=true` only alongside a mailer that delivers; the
endpoint, broker and tests are already in place.

**Known caveat — offline logout.** `AuthController.logout()` swallows a
network failure and signs out locally anyway: the stored token is deleted and
the app returns to `/login`. If the server was unreachable, the *remote*
session therefore survives until it expires or is revoked from
`GET /auth/sessions`. There is deliberately no retry queue. A queued request
would have to carry the very token whose purpose is to delete itself — holding
it past the moment of sign-out keeps alive a credential the user believes is
dead, and a "revoke later" job that never runs because the device stays
offline is worse than an honest, immediate local sign-out. On a lost or shared
device, changing the password from another session is the real fix: it
deletes every other token.

**Never stored:** passwords in plain text, tokens in logs, tokens in `shared_preferences`.

**Flutter side:** token lives in `flutter_secure_storage` (iOS Keychain /
Android Keystore) under a single fixed key, never written to plain
preferences, never logged, never displayed. The device name sent at login is a
random per-install id — it distinguishes installs without carrying anything
about the account.

---

## 3. Authorization — RBAC + Policies

Two layers, both mandatory:

### 3.1 Permission layer (`spatie/laravel-permission`)

**Implemented in Phase 2 ✅** — `spatie/laravel-permission` **^6.25**, 10 roles,
40 permissions, 167 grants.

> **Version pin matters:** v7/v8 of this package require PHP `^8.3`. This environment
> runs **PHP 8.2.4**, so Composer correctly resolves to **6.25.0** (supports Laravel
> 8–13, PHP `^8.0`). Upgrading PHP to 8.3+ before bumping the package.

Every permission is `resource.action`, lowercase:

```
dashboard.view
employees.view        employees.create     employees.update     employees.delete
employees.salary.view
departments.view      departments.manage
designations.view     designations.manage
attendance.view       attendance.manage
leave.view            leave.request        leave.approve        leave.manage
payroll.view          payroll.manage
projects.view         projects.manage
sites.view            sites.manage
shifts.view           shifts.manage
assignments.view      assignments.manage
reports.view          reports.export
documents.view        documents.manage
expenses.view         expenses.approve     expenses.manage
settings.view         settings.manage
roles.view            roles.manage
users.view            users.manage
audit.view
```

| | |
|---|---|
| Roles | Super Admin, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Engineer, Site Supervisor, Finance, Management, Employee |
| Super Admin | Holds `['*']` — resolved from the catalogue at seed time, so new permissions are picked up automatically |
| All other roles | Explicit allow-list; anything absent is **denied** |
| Source of truth | `PermissionSeeder::PERMISSIONS` (catalogue) + `RolePermissionSeeder::MAP` (grants) |
| Enforcement | Middleware aliases `permission`, `role`, `role_or_permission` registered in `bootstrap/app.php` |

**Enforcement is server-side only.** The Flutter UI hiding a button is a usability
convenience, never a control. Example:

```php
Route::middleware(['auth:sanctum', 'permission:payroll.manage'])
    ->get('/payroll/runs', [PayrollController::class, 'index']);
```

Verified by `RbacTest`: an authorized user gets `200`, a user with the wrong role gets
`403`, and an unauthenticated caller gets `401` — before any controller code runs.

### 3.2 Resource layer (Policies)

Permissions answer *"may this role do X?"*. Policies answer *"may this user do X to
**this** record?"*.

**Implemented in Phase 4 ✅** — six policies, each authorized from a base
`Controller` using `AuthorizesRequests`, so a controller cannot forget to ask:

| Policy | What it decides |
|---|---|
| `DepartmentPolicy` | view / create / update / delete a department |
| `DesignationPolicy` | view / create / update / delete a designation |
| `ProjectPolicy` | view / create / update / delete a project |
| `SitePolicy` | view / create / update / delete a site |
| `EmployeePolicy` | view / update / delete another person; **and `viewSalary`** |
| `EmployeeSiteAssignmentPolicy` | read / create / close an assignment |

`EmployeePolicy::view` is the reason `GET /employees/{id}` carries **no**
`permission:` middleware: an ordinary employee holding no `*.view` permission
must still be able to open their own profile, and the policy is what separates
"yours" from "everybody else's" — answering `403` for anything else.

**`EmployeePolicy::viewSalary` requires both `employees.salary.view` *and*
`view`.** Holding `employees.view` therefore never implies payroll: a list
projection (`EmployeeResource`) has no `salary` key at all, only
`EmployeeDetailResource` may carry it, and it carries `salary_visible: false`
to anyone who may not read it. The rule is re-enforced in
`StoreEmployeeRequest` / `UpdateEmployeeRequest`, which answer **422** if a
`salary` key arrives from a caller without the permission — a body the UI
chose not to render cannot be a way in.

> **Hiding a button in Flutter is not authorization.** Every rule must also be enforced
> in Laravel. The Flutter UI hides controls only for usability.

**Status:** ✅ Permission layer live (Phase 2) · ✅ Permission-gated routes since Phase 3 · ✅ Policies for the Phase 4 modules (Phase 4) · ⬜ Attendance / leave / payroll policies in their own phases

> **Scope note:** permissions are a *coarse gate*. Row scoping belongs to the
> policy, and for the modules that exist today that split is wired: every Phase
> 4 route carries `permission:` middleware except the two `show` routes that
> must stay open to a person reading their own row.

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

**Status:** ✅ Auth endpoints since Phase 3 (`LoginRequest`, `ChangePasswordRequest`,
`PasswordResetRequest`), mass assignment closed on every model · ✅ Phase 4 write
endpoints each have their own Form Request (`StoreDepartmentRequest`,
`UpdateDepartmentRequest`, `StoreDesignationRequest`, `UpdateDesignationRequest`,
`StoreEmployeeRequest`, `UpdateEmployeeRequest`, `StoreProjectRequest`,
`UpdateProjectRequest`, `StoreSiteRequest`, `UpdateSiteRequest`,
`StoreEmployeeSiteAssignmentRequest`, `UpdateEmployeeSiteAssignmentRequest`) ·
⬜ one per write endpoint as later modules land

`UpdateEmployeeSiteAssignmentRequest` is worth naming: it accepts **only**
`status` ∈ `{ended, cancelled}` and `end_date`. Identity fields
(`employee_id`, `site_id`, …) come back as per-field 422s, so an assignment
cannot be pointed at a different person or place by an edit.
`StoreEmployeeRequest` rejects `photo_path` and `user_id` outright — neither
is a client-supplied field.

---

## 7. Rate Limiting

Numbers live in `backend/config/rate_limiting.php` and are read from `.env`, so
tightening a limit is a config change — never a hunt through routes for a
literal.

| Scope | Limit | Key | Status |
|---|---|---|---|
| `POST /auth/login` | 5 / min | client IP | ✅ Phase 3 |
| `POST /auth/forgot-password` · `POST /auth/reset-password` | 5 / 15 min | client IP | ✅ Phase 3 |
| Attendance endpoints | 30 / min | client IP | ⬜ Phase 5 |
| General API | 60 / min | client IP | ⬜ Planned |
| Exports (PDF/Excel) | 10 / min | client IP | ⬜ Phase 12 |

On breach → `429` in the standard envelope with `Retry-After`, rendered by the
exception handler rather than by a per-limiter `Limit::response()` callback, so
the header survives and the body keeps its shape.

**Keyed by IP, never by email.** See §2 — keying by email hands any attacker a
lockout weapon aimed at a chosen victim.

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

**Phase 4 prepared the ground without faking it.** No activity log was wired,
because a table nothing writes to is worse than none — but every mutation that
will need one is already structured for it: services own the non-trivial
writes, transactions wrap them, and an assignment records `created_by` at
insert. Adding logging later is one call inside the service, not a rewrite.

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

**Status:** ✅ `.gitignore` in place · ✅ `.env.example` with placeholders only —
including the Phase 3 keys (`PASSWORD_RESET_ENABLED`, `LOGIN_RATE_LIMIT_*`,
`PASSWORD_RESET_RATE_LIMIT_*`, `BCRYPT_ROUNDS`)

---

## 10. Device & App Security (Flutter)

| Control | Detail | Status |
|---|---|---|
| Token storage | `flutter_secure_storage` → Keychain / Keystore, single fixed key, never plain storage | ✅ Phase 3 |
| Base URL | Baked in with `--dart-define` — no runtime setting that could repoint the app at an attacker's server | ✅ Phase 3 |
| Credentials in UI | Password masked by default; never logged, never placed in `AuthState` | ✅ Phase 3 |
| Session invalidation | Any request presenting a rejected token drops the local session and returns to `/login` | ✅ Phase 3 |
| Screenshotting of sensitive screens | Consider `flutter_windowsecure` for salary screens | ⬜ Phase 11 |
| Certificate pinning | Optional, for high-security deployments | ⬜ Optional |
| Debug logging | Disabled in release builds | ⬜ Phase 13 |
| Root/jailbreak detection | Optional, warn-only (not a substitute for server-side auth) | ⬜ Optional |
| App permissions | Request only at the moment of use, with clear explanation | ⬜ Phase 5 |

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

Enforced by `Password::min(8)->letters()->numbers()` on both
`change-password` and `reset-password`.

| Rule | Value | Status |
|---|---|---|
| Minimum length | 8 characters | ✅ Phase 3 |
| Complexity | At least one letter and one number | ✅ Phase 3 |
| Confirmation | `password_confirmation` must match | ✅ Phase 3 |
| Hashing | `bcrypt` cost 12 (`BCRYPT_ROUNDS=12`) | ✅ Phase 3 |
| Current-password proof | Required on change; compared with `Hash::check` | ✅ Phase 3 |
| Reset link expiry | 60 minutes, invalidated on use | ✅ Phase 3 |
| Reset delivery | One-time signed token via password broker | ✅ Built · ⬜ gated (§2) |
| History — prevent reuse of last 5 | Not yet enforced | ⬜ Planned |

> The 8-character minimum with letters + numbers is deliberately not a
> special-character maze: rules users work around with `Password1!` produce
> weaker secrets than rules they can actually satisfy. Coverage lives in
> `AuthenticationTest` and `PasswordResetTest`; revisit the rule once password
> history lands.

---

## 13. Security Checklist by Phase

| Phase | Controls delivered |
|---|---|
| 1 | `.gitignore` secret blocking, private-storage plan |
| 2 | RBAC (roles, permissions, middleware), settings as the single config authority |
| 3 | Sanctum auth, one-token-per-device, rate limiting, password policy, session revocation, response-envelope exception handling. **Login audit deferred to Phase 5** with §8 |
| 4 | Six policies (department, designation, employee, project, site, employee-site-assignment), 12 Form Requests, coarse `permission:` middleware on every route except the two self-read `show`s, `employees.salary.view` separated from `employees.view`, assignment identity fields frozen against edits |
| 5 | Geofence server validation, selfie private storage + upload validation, attendance audit, login audit |
| 6 | Offline sync idempotency, server re-validation of offline GPS |
| 9 | Leave approval audit, LOP conversion audit |
| 10 | Document private storage, signed URLs, expiry jobs |
| 11 | Salary access control (own-only), payroll audit |
| 13 | Full security audit, penetration-style test pass, deployment hardening |

---

## 14. Reporting a Security Issue

Do not open a public issue for a security vulnerability. Contact the maintainer
privately with reproduction steps.
