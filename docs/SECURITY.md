# Security

> **Status:** Phase 5 — authentication, rate limiting, the password policy, the
> permission layer, row-level policies, and now **GPS attendance controls**
> (server-authoritative geofence, private selfie storage, location and camera
> permissions) are live and tested. **Attendance selfie sanitisation is now
> enforced server-side** (§4.3) — metadata stripping no longer depends on the
> Flutter client. Transport hardening and audit logging remain phased ahead
> (§5, §8). Individual controls are marked with their phase below.

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
| Another employee's selfie | Read by browsing, guessing a path, or a cached link | Privacy violation — closed by `viewSelfie` + UUID names + `no-store` |
| Offline replay | A queued event accepted twice | Two attendance rows, or a day counted twice |
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
(§8) is the complementary control; **it did not arrive with Phase 5** —
attendance was built without a login-audit trail, and §8 remains the honest
description of that gap.

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
40 permissions, **168 grants** (Phase 5 granted the existing `attendance.view`
to the `Employee` role so a person can read back the day they recorded).

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
| `AttendancePolicy` | view a day, list days, read the photograph (`viewAny` / `view` / `viewSelfie`), and — separately — `checkIn` / `checkOut` for oneself |
| `SiteVisitPolicy` | list visits, read one, and start/end one's own |

`EmployeePolicy::view` is the reason `GET /employees/{id}` carries **no**
`permission:` middleware: an ordinary employee holding no `*.view` permission
must still be able to open their own profile, and the policy is what separates
"yours" from "everybody else's" — answering `403` for anything else.

**`AttendancePolicy` extends that same idea to a whole day.** Recording that
you arrived is not a privilege anybody grants you, so `checkIn` and `checkOut`
are true for any authenticated employee with an active profile, and the
seven Phase 5 routes that record or read your own day carry no `permission:`
middleware at all. Reading is the other side: `viewAny` requires
`attendance.manage` *or* `employees.view`, and for a caller holding neither it pins the query to their
own employee id — so an employee holding nothing but the `attendance.view` they were just granted sees exactly one row: theirs.
`viewSelfie` answers the same question for the photograph, which is why no
separate "photo" permission exists to be forgotten about.

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

**Status:** ✅ Permission layer live (Phase 2) · ✅ Permission-gated routes since Phase 3 · ✅ Policies for the Phase 4 modules (Phase 4) · ✅ Attendance + site-visit policies (Phase 5) · ⬜ Leave / payroll / document policies in their own phases

> **Scope note:** permissions are a *coarse gate*. Row scoping belongs to the
> policy, and for the modules that exist today that split is wired: every Phase
> 4 route carries `permission:` middleware except the two `show` routes that
> must stay open to a person reading their own row — and Phase 5 followed the
> same rule for seven more routes, where the policy is the only gate because
> checking yourself in is not something a role grants you. One more
> anti-pattern was found and removed in Phase 5: two queries widened their row
> scope with an `orWhereExists` whose predicate came from a condition that can
> be empty, which would have returned *every* attendance row for a caller with
> no filters. The clause is now only applied when its scope is non-empty, and
> `AttendanceVisibilityTest::test_a_site_supervisor_with_nothing_to_run_reads_only_their_own_day`
> is what proves it: a listed supervisor who runs no site gets their own rows
> and nobody else's.

---

## 4. File Storage Security

### 4.1 Private storage ✅ Phase 5 (selfies)

Selfies, passports, Emirates IDs, visas, employment contracts, medical documents and
salary documents are stored on a **private disk** — never in `public/`.

```
backend/storage/app/private/
├── attendance-selfies/{employeeId}/{uuid}.{jpg|png|webp}   ← Phase 5
├── documents/employees/
├── documents/payroll/
└── reports/
```

`storage/app/private` has no `/storage/...` URL and no directory listing, so
the path below is not guessable and not browsable.

### 4.2 Access control ✅ Phase 5 (selfies)

```
GET /api/v1/attendance/{id}/selfie  →  AttendancePolicy::viewSelfie
                                      →  SelfieStore::isSafe($path)
                                      →  streamed file, Cache-Control: no-store
```

Four properties hold:

| Property | How |
|---|---|
| Reachable only by an authorised reader | `viewSelfie` — the same policy that answers every other row question, so revoking a permission revokes the photograph too |
| Not guessable | the name is a UUID minted by the server, never the client's filename |
| Not cacheable | `no-store, no-cache, must-revalidate, max-age=0`, so a shared tablet does not hand the next person someone else's face |
| Not path-traversable | `SelfieStore::isSafe()` rejects `..`, absolute paths and anything outside `attendance-selfies/` before touching the disk |

No response ever contains a filesystem path or a base64 image:
`AttendanceResource` reports `has_selfie`, not `_path`. There is no route
that lists or streams another employee's selfie, and no signed URL — a
signed URL is a bearer secret with a lifetime, and it would put the storage
layout and the employee id into a link that outlives the permission that
issued it.

### 4.3 Upload validation **and sanitisation** (server-side, always) ✅ Phase 5, hardened

| Check | Rule |
|---|---|
| MIME type | Sniffed from the bytes by `finfo`, **not** the client-supplied header — checked in `StoreCheckInRequest` *and* again in `SelfieStore` |
| Extension | Must be in the allow-list; the stored extension is chosen by the **encoder**, so it is always `.jpg` regardless of what arrived |
| Size | Selfies ≤ `hrms.storage.selfie_max_kilobytes` (5120 KB); documents ≤ 10 MB (configurable, Phase 10) |
| Real image content | `App\Rules\ImageContent` parses the header itself and `SelfieSanitizer` decodes the pixels — a PDF renamed to `.jpg` is refused by three independent answers |
| Pixel budget | ≤ `hrms.storage.selfie_max_pixels` (default 16 777 216 = 4096 × 4096), because a decoded image costs ~4 bytes a pixel and one crafted request must not be able to ask for a gigabyte of buffer |
| Filename | **Never trusted** — `{employeeId}/{uuid}.jpg` is generated server-side and the client's name is never read |
| Path containment | `SelfieStore::isSafe()` before any read or delete |
| Cleanup | a check-in that throws after the file was written deletes it |

`jpg`, `jpeg`, `png`, `webp` only, unless explicitly extended.

#### Metadata stripping is enforced on **both** sides — Laravel is the boundary

| Layer | What it does | Why it exists |
|---|---|---|
| **Flutter** — `SelfieCompressor` | Decodes and re-encodes before upload | A courtesy to a patchy site signal: it gets the frame under 5 MB without a second round-trip. It is **not** a control. |
| **Laravel** — `SelfieSanitizer` | Decodes the upload, flattens any alpha onto white, re-encodes as JPEG 85, and writes **only** those bytes | **The security boundary.** It runs for every request, including from clients that have never seen the app. |

**How the stripping works.** There is no metadata-stripping step, because
there cannot be one worth trusting: EXIF — the GPS fix, the camera make and
model, the software string, sometimes an embedded thumbnail of a different
frame entirely — lives in JPEG APP segments that a pixel decoder skips.
Decoding with GD and writing the pixels back out therefore writes none of
it. The original bytes are read exactly once, into memory, and are never
written anywhere; the original filename is never read at all.

This closes the gap Phase 5 left open ("EXIF is stripped by the app, not by
the server"). It uses **PHP GD**, which is already bundled with this PHP
build (`imagecreatefromstring`, `imagejpeg`) — no new Composer dependency
was added and none is required.

**Fail closed.** If GD were unavailable, `SelfieSanitizer` refuses to store
*anything* rather than falling back to the raw upload: a missing extension
must never become a metadata bypass.

**Not implemented, deliberately:** facial recognition, biometric matching of
any kind, and any automatic identification of the person in the frame.

**Audit logging is still not implemented** — see §13. It is scheduled for
the dedicated audit/security phase, which will record who read or changed
which record. Reading a selfie today is authorised by `viewSelfie` and
denied when it should be, but the fact of the read is not persisted.

**Status:** ✅ Phase 5 (selfie) · ✅ selfie sanitisation (this pass) ·
⬜ Phase 10 (documents) · ⬜ audit/security phase

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
| Out-of-range GPS | `latitude` `-90..90`, `longitude` `-180..180`, `accuracy` `0..100000`, all `numeric` — a coordinate that cannot exist is rejected before any distance is computed |
| Client-trusted facts | No `employee_id`, `attendance_date`, `project_id`, distance, minute or `status` field exists in any Phase 5 request; `source: manual` is refused from a client |
| Uploaded filenames | Never read from the request — see §4.3 |

**Status:** ✅ Auth endpoints since Phase 3 (`LoginRequest`, `ChangePasswordRequest`,
`PasswordResetRequest`), mass assignment closed on every model · ✅ Phase 4 write
endpoints each have their own Form Request (`StoreDepartmentRequest`,
`UpdateDepartmentRequest`, `StoreDesignationRequest`, `UpdateDesignationRequest`,
`StoreEmployeeRequest`, `UpdateEmployeeRequest`, `StoreProjectRequest`,
`UpdateProjectRequest`, `StoreSiteRequest`, `UpdateSiteRequest`,
`StoreEmployeeSiteAssignmentRequest`, `UpdateEmployeeSiteAssignmentRequest`) ·
✅ Phase 5 writes (`StoreCheckInRequest`, `StoreCheckOutRequest`,
`StartSiteVisitRequest`, `EndSiteVisitRequest`) ·
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
| `POST /attendance/check-in` · `check-out` · `site-visits/start` · `site-visits/{id}/end` | 30 / min | authenticated user id | ✅ Phase 5 |
| General API | 60 / min | client IP | ⬜ Planned |
| Exports (PDF/Excel) | 10 / min | client IP | ⬜ Phase 12 |

On breach → `429` in the standard envelope with `Retry-After`, rendered by the
exception handler rather than by a per-limiter `Limit::response()` callback, so
the header survives and the body keeps its shape.

**Keyed by IP, never by email.** See §2 — keying by email hands any attacker a
lockout weapon aimed at a chosen victim.

**The attendance limiter is keyed by user id instead** — deliberately. A site
office is one NAT address, and an IP bucket would refuse the fourth member of
a crew to reach the gate in the same minute. Per-user still bounds the thing
that matters: how fast one account can replay check-ins. It is attached to
the four write routes only; the reads are unthrottled because punishing a
refresh is not a control.

What the limiter is *not* is a defence against duplicate attendance. That is
the unique index plus `client_event_id` (§13) — rate limits slow an attacker
down, they do not make a second row impossible.

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

**Status:** ⬜ Not yet implemented — neither Phase 5 nor the Phase 5 selfie
hardening pass changed this. **Audit logging remains scheduled for the
dedicated audit/security phase**, and is not being faked or partially
shipped in the meantime.

**Phase 4 prepared the ground without faking it**, and **Phase 5 did the
same**. No activity log was wired, because a table nothing writes to is
worse than none — but every mutation that will need one is already
structured for it: services own the non-trivial writes, transactions wrap
them, an assignment records `created_by` at insert, and `attendances` and
`site_visits` each carry a `source` column (`online` / `offline` / `manual`)
with `manually_adjusted` reserved as a status so the future override writes
a recognisable value rather than quietly overwriting a `present`. Adding
logging later is one call inside the service, not a rewrite.

The honest gaps: **attendance override is not audited because attendance
override does not exist yet**, and **login success/failure is still not
recorded anywhere** — see §2. Reading a selfie is authorised by
`viewSelfie`, but the read itself is not persisted as an activity row.

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
| App permissions | Request only at the moment of use, with a plain-language reason first; foreground location only — `ACCESS_FINE_LOCATION` / `ACCESS_COARSE_LOCATION` / `CAMERA` in the manifest and **no** `ACCESS_BACKGROUND_LOCATION` | ✅ Phase 5 |
| Permission denial | The app keeps working and explains the limitation; never a crash, never a silent retry loop; a permanently denied state offers Settings and a pre-filled "Allow only while using the app" guide | ✅ Phase 5 |
| GPS unavailable | Detects the location-services switch itself and says so, separately from a permission denial | ✅ Phase 5 |
| Offline queue | Events written with an event UUID and `pending_sync`; the network error alone queues — 403/409/422 are shown, not queued | ✅ Phase 5 |

---

## 11. Privacy & Location

**Location is collected only for specific business actions:**

- Attendance check-in / check-out
- Site visits
- Site activity reports *(not built yet)*

**Explicitly NOT implemented:**

- ❌ Continuous background GPS tracking — no background permission is
  declared, nothing polls, nothing uploads when the app is idle
- ❌ Location monitoring when the app is idle
- ❌ Facial recognition on selfies — the photograph is evidence read by a
  person during a dispute, never scored by a model
- ❌ A location history. There is nowhere to store one: a coordinate is
  written only when someone presses CHECK IN, START VISIT, END VISIT or
  CHECK OUT, and every pair of them has a column in §4's tables

**Employee-facing transparency:**

- Permissions requested contextually, never all at once on first launch
- Plain-language explanation of *why* location and camera are needed, shown
  before the system dialog rather than after it
- Graceful handling of denial — the employee keeps using the app, and the
  limitation is explained clearly (with an HR contact for exceptions). A
  permanently denied state routes to Settings; a switched-off GPS is named
  as such, not mistaken for a refusal
- The screen labels its own readings **advisory**: the phone may say
  "outside the 100 m radius" as a warning, and always with the note that
  the server measures again — because the request carries coordinates, not
  a verdict

**Data minimisation:** GPS accuracy is stored for attendance validation, then retained
only as long as the business/retention policy requires. The check-in selfie
is the only image kept, at one per first check-in per day, and it leaves the
phone as a compressed JPEG rather than the full camera frame.

**Status:** ✅ Phase 5 — the location and camera parts of this section are now
properties of shipped code, not a plan. The remaining rows (site activity
reports, retention jobs) belong to later phases.

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
| 5 | Server-authoritative geofence (lat/lng range + accuracy ceiling + distance always computed, never accepted), site-assignment validation before any punch, private selfie storage behind `viewSelfie` with no-store and no paths in responses, MIME/extension/size checks twice over, `Attendance` + `SiteVisit` policies with fail-closed row scoping, `attendance.view` granted to `Employee` (168 grants), `attendance` rate limiter (30/min per user) on the four writes, foreground-only location + camera permissions, offline queue with `client_event_id` idempotency and server re-validation on sync. **Not delivered: attendance override audit (no override exists) and login audit (§8) — both still outstanding** |
| 6–8 | Leave, payroll, documents, expenses, notifications — planned with their own audit rows |
| 9 | Leave approval audit, LOP conversion audit |
| 10 | Document private storage, signed URLs, expiry jobs |
| 11 | Salary access control (own-only), payroll audit |
| 13 | Full security audit, penetration-style test pass, deployment hardening |

### Selfie hardening pass (after Phase 5, before Phase 6)

Server-side image sanitisation for attendance selfies, closing the "EXIF is
stripped by the app, not by the server" gap §4.3 used to record:

- `SelfieSanitizer` — GD decode → flatten alpha → re-encode as JPEG 85;
  only the re-encoded bytes are ever written, so EXIF/GPS/camera metadata
  and the client filename cannot survive
- `App\Rules\ImageContent` — header-level "is this an image" plus a pixel
  budget, as a field error at the request
- fail-closed when the decoder is unavailable; the original upload is never
  stored, even temporarily
- one format on disk (`.jpg`), server-minted UUID name, private disk,
  `viewSelfie` + `no-store` unchanged
- 13 tests in `AttendanceSelfieSanitizationTest`

**Not delivered by this pass, unchanged:** facial recognition (deliberately
never), audit logging (scheduled for the dedicated audit/security phase).

---

## 14. Reporting a Security Issue

Do not open a public issue for a security vulnerability. Contact the maintainer
privately with reproduction steps.
