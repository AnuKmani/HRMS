# Security

> **Status:** Phase 11 — authentication, rate limiting, the password policy, a
> **95-permission** catalogue, row-level policies (30 at Phase 11, incl.
> `AssetPolicy` and `EmployeeTrainingPolicy`), GPS attendance
> controls (server-authoritative geofence, private selfie storage, location and
> camera permissions), server-side selfie sanitisation (§4.3), the approval and
> leave controls (no self-approval, current-step-only decisions, private
> medical-certificate storage, §4.4), the **site vertical slice**: derived
> authorship and derived project on every report, private re-encoded report
> photographs behind their own policy (§4.5), a server-rendered PDF that is
> never stored (§4.6), and a submit-time GPS reading the server validates
> rather than trusts — the **payroll vertical slice**: a one-way money
> ladder with `payroll.lock` held by one role, salary figures that are
> `DECIMAL` and formatted in exactly one place, a loan schedule that can be
> taken only once and never below the configurable **net-salary floor**,
> carry-forward of any repayment that would not fit, and salary slips and
> certificates rendered on demand with nothing stored and nothing to link to
> (§4.7) — the **expense vertical slice**: three new permissions,
> `expenses.manage` held by four roles rather
> than granted broadly, an `ExpensePolicy` that fails closed to your own
> claims, five statuses (`draft → pending → approved | rejected | cancelled`)
> reachable only through `ExpenseService`, a figure a client sends treated as
> a suggestion and re-validated on the server (§6), and receipt files on
> private storage whose path never leaves the API (§4.8) — the
> **employee-document and onboarding slice**: seven more permissions
> where **`documents.manage` and
> `onboarding.manage` are held by exactly three roles**, row scope that
> fails closed to your own file, the server's own answer to "is this
> expired" on every row (§4.9), identity documents on private storage under
> a minted name with images re-encoded and PDFs sniffed (§4.9), a nightly
> scan that cannot notify twice, and **bank details in a 1:1 side table on
> `encrypted` casts, outside every generic resource and behind two routes
> with no `permission:` at all** (§4.10) — and now the **training and asset
> slice**: fifteen more permissions (**95 in all, 462 grants**) where
> **`training.certificates.view` and `assets.manage` are held by the same
> three roles**, an enrolment ledger whose duplicate is refused by the
> database as well as by the service, a certificate on private storage whose
> route is gated *not* by `training.manage` (§4.11), a nightly scan that
> cannot notify twice, and **an asset's `purchase_cost` omitted from the
> resource rather than nulled**, so a missing figure can never be read as a
> free one (§4.11). Transport hardening
> and audit logging remain phased ahead (§5, §8). Individual controls are
> marked with their phase below.

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

**Implemented in Phase 2 ✅, extended in Phases 4–11** — `spatie/laravel-permission`
**^6.25**, 10 roles, **95 permissions**, **462 grants**. Phase 4 added
`employees.salary.view`; Phase 5 granted the existing `attendance.view` to the
`Employee` role so a person can read back the day they recorded; Phase 6 added 11
(`approvals.view/manage`, `leave.balance.view/manage`, `holidays.manage`,
`timesheets.view/manage`, `overtime.view/create/approve/manage`) and retired
`leave.request` in favour of `leave.create`; Phase 7 added 8
(`site_activity_reports.{view,create,update}` and
`daily_site_reports.{view,create,update,manage,pdf}`); **Phase 8 added 11**
(`loans.{view,create,approve,manage}`, `salary_slips.{view,manage}`,
`salary_certificates.{view,manage}`, and the three payroll verbs that
`payroll.view`/`payroll.manage` could not express — `payroll.process`,
`payroll.lock`, `payroll.summary.view`); **Phase 9 added 3**
(`expenses.create`, `expenses.update`, `expenses.receipts.view` — the three
verbs the pre-existing `expenses.view`/`expenses.approve`/`expenses.manage`
could not express), taking the catalogue **70 → 73** and the grant map
**332 → 364**; **Phase 10 added 7** (the six `documents.*` verbs listed in
§3.1b, with `documents.view` already in the catalogue, plus the new
`onboarding.{view,manage}` pair), taking the catalogue **73 → 80** and the
grant map **364 → 401**; **Phase 11 added 15** (the eight `training.*` and
seven `assets.*` verbs listed in §3.1c), taking the catalogue **80 → 95**
and the grant map **401 → 462**.

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
approvals.view        approvals.manage
leave.view            leave.create         leave.approve        leave.manage
leave.balance.view    leave.balance.manage
holidays.manage
timesheets.view       timesheets.manage
overtime.view         overtime.create      overtime.approve     overtime.manage
payroll.view          payroll.manage        payroll.process      payroll.lock
payroll.summary.view
salary_slips.view     salary_slips.manage
salary_certificates.view   salary_certificates.manage
loans.view            loans.create          loans.approve        loans.manage
projects.view         projects.manage
sites.view            sites.manage
site_activity_reports.view   site_activity_reports.create   site_activity_reports.update
daily_site_reports.view      daily_site_reports.create      daily_site_reports.update
daily_site_reports.manage    daily_site_reports.pdf
shifts.view           shifts.manage
assignments.view      assignments.manage
reports.view          reports.export
documents.view        documents.manage
expenses.view         expenses.approve     expenses.manage
expenses.create       expenses.update      expenses.receipts.view
training.view         training.create      training.update       training.manage
training.assign       training.complete    training.certificates.view
training.expiry.view
assets.view           assets.create        assets.update         assets.manage
assets.assign         assets.return        assets.history.view
settings.view         settings.manage
roles.view            roles.manage
users.view            users.manage
audit.view
```

Four absences are deliberate:

- **No `holidays.view`.** Reading the calendar is owed to every signed-in
  account — you cannot plan leave around days you are forbidden to see — so
  `GET /holidays` carries no `permission:` middleware and
  `HolidayPolicy::viewAny()` returns `true` unconditionally. Only writing is
  gated (`holidays.manage`).
- **No `timesheet.approve`.** Timesheets are derived snapshots with nothing
  to approve (ARCHITECTURE §5.9); a permission that authorised a decision the
  system does not offer would be a button nobody could press.
- **No `daily_site_reports.approve`.** Phase 7 files the official report and
  stops: `daily_site_reports.manage` covers the read-and-correct work that
  exists today, and `approved_at` is an empty column waiting for a decision
  Phase 7 deliberately does not offer. `.pdf` *is* its own permission rather
  than riding on `.view`, because being shown a list and being handed
  something you can forward are different acts.
- **No `payroll.reverse`, no `payroll.delete`, no `loan.writeoff`.** Phase 8's
  money ladder only climbs: `draft → calculated → reviewed → processed →
  locked`, and a repayment schedule that has started may only be completed.
  A permission that authorised a reversal would promise a capability the
  services refuse, so the correction path is a new positive entry (a `+`
  adjustment, a `skipped` installment) that leaves the original figure
  standing — which is what an auditor needs to see anyway.
- **Nothing authorises a negative net salary.** The floor
  (`payroll.minimum_net_salary`, `0` by default) is enforced *inside* the
  calculation, not behind a grant: a run may take a loan or advance
  installment only up to what leaves `net_salary` at or above it, and the
  remainder stays outstanding for the next run. Because it is a `settings`
  row rather than a permission, no role — Super Admin included — can switch
  it off by calling an endpoint; it changes through an audited settings
  write, and a change afterwards **cannot re-cut a deduction already signed
  off** (`recalculate` on `reviewed`/`processed`/`locked` → 409, a re-run
  reports `skipped`). Loss of pay and approved adjustments are never
  rewritten to protect the floor, so it cannot be used to make a payslip
  prettier than the month was.

**Who sees whose money (Phase 8).** Every payroll read is narrowed to the
caller unless the caller holds `payroll.manage`, so `payroll.view` alone is
"My payslip", not "the company's payroll". `payroll.summary.view` is the one
read that returns aggregates with **no names and no rows** — it exists so
Management can be given totals without being given the ledger.
`salary_slips.view` is a separate family for the same reason: a role may be
handed its own payslips while being kept out of everyone else's.
`loans.view` has **no `employees.view` fallback** — a borrower's loan is
visible to them because they are the borrower, not because they can browse
the directory.

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

### 3.1a Expense permissions — ✅ Phase 9

Three verbs join the catalogue on top of the three that already existed:
`expenses.view`, `expenses.approve` and `expenses.manage` were Phase 2
inventory; **Phase 9 added `expenses.create`, `expenses.update` and
`expenses.receipts.view`**, for **73 permissions / 364 grants** in all at
that point (**80 / 401** after Phase 10 — §3.1).

| Permission | Roles holding it (of 10) |
|---|---|
| `expenses.view` | Employee, Finance, HR Admin, HR Executive, Management, Payroll Admin, Project Manager, Site Engineer, Site Supervisor, Super Admin — **10** |
| `expenses.create` | Employee, HR Admin, HR Executive, Project Manager, Site Engineer, Site Supervisor, Super Admin — **7** |
| `expenses.update` | every `expenses.view` role except Management — **9** |
| `expenses.approve` | Finance, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Supervisor, Super Admin — **7** |
| `expenses.manage` | Finance, HR Admin, Payroll Admin, Super Admin — **4** |
| `expenses.receipts.view` | the same seven as `expenses.approve` |

Two edges of that matrix are deliberate:

- **`expenses.manage` is not granted broadly.** Four roles only. It is the
  back-office verb — push somebody else's claim through, withdraw it, correct
  a draft, attach or remove evidence — and the permission EXP-STD's second
  link resolves (`permission: expenses.manage`), so holding it means "I may
  answer the finance link", not "I may read every claim" (that is
  `expenses.view` narrowed by the policy, below).
- **Project Manager may approve its own scope but is excluded from the
  `permission: expenses.manage` link.** It sits in the seven-role
  `expenses.approve` row, so a Project Manager holding the chain's current
  step can sign it; it is absent from the four-role manage row, so the same
  person can never satisfy a step written as `permission: expenses.manage`.
  Management, at the other edge, is read-only: it holds `expenses.view` and
  deliberately not `create` or `update`.

The split between `expenses.view` and `expenses.receipts.view` is the same
one §4.6 draws for `.pdf`: being shown the numbers of a claim and being
handed the invoice behind it are different acts, so they are different
grants.

### 3.1b Document & onboarding permissions — ✅ Phase 10

**Phase 10 added seven** — `documents.create`, `documents.update`,
`documents.verify`, `documents.delete`, `documents.expiry.view`,
`documents.manage` and the new pair `onboarding.view` / `onboarding.manage`
(`documents.view` was already in the catalogue, so eight names and seven
rows), for **80 permissions / 401 grants** in all.

| Permission | Roles holding it (of 10) |
|---|---|
| `documents.view` | every role — **10** (an employee reading their own file is the base case) |
| `documents.create` | Employee, HR Admin, HR Executive, Project Manager, Site Engineer, Site Supervisor, Super Admin — **7** |
| `documents.update` | the same seven — **7** |
| `documents.expiry.view` | HR Admin, HR Executive, Payroll Admin, Super Admin — **4** |
| `documents.verify` | HR Admin, HR Executive, Super Admin — **3** |
| `documents.delete` | the same three — **3** |
| `documents.manage` | the same three — **3** |
| `onboarding.view` | every role — **10** |
| `onboarding.manage` | HR Admin, HR Executive, Super Admin — **3** |

Three edges of that matrix are the whole design:

- **`documents.manage` at three roles is the row-scope gate, and that is
  the point.** `Visibility::mayViewOthersDocuments()` requires
  **`documents.view` *and* `documents.manage`**, and `employees.view` is
  deliberately **not** a third door in. Project Manager, Site Supervisor and
  Site Engineer hold `.view` + `.create` + `.update` — they may file a
  colleague's paperwork — and are absent from the three-role manage row, so
  the same people can never open a colleague's passport, Emirates ID, visa,
  medical record or contract. Supervising somebody must not be a way of
  reading their identity documents.
- **`documents.expiry.view` is its own grant rather than a rider on
  `.view`.** The expiry report answers "who in the whole company is about to
  lapse", which is a different question from "show me my own file" — and the
  ten roles holding `.view` include every Employee. Withheld from Project
  Manager, Site Supervisor, Site Engineer, Finance, Management and Employee;
  held by HR Admin, HR Executive, Payroll Admin and Super Admin.
- **`onboarding.view` on ten roles does not mean ten roles can browse the
  joiners.** Without `onboarding.manage` the row scope narrows the directory
  to *yourself*: an employee may ask where they stand, and cannot see that a
  colleague has not yet handed in their visa. `onboarding.manage` — moving
  stages, completing a record — is held by the same three.

**Bank details are gated twice more on top.**
`GET|PUT /employees/{employee}/bank-account` carry **no `permission:`
middleware at all**; `EmployeePolicy::viewBankAccount` / `updateBankAccount`
decide per row, and there is no role in the catalogue whose *job* is to read
everybody's IBAN. The route exists as its own family precisely so that
`EmployeeResource` cannot leak the columns by forgetting a `hidden()`.

### 3.1c Training & asset permissions — ✅ Phase 11

**Phase 11 added fifteen** — the eight `training.*` verbs and the seven
`assets.*` verbs — for **95 permissions / 462 grants** in all.

| Permission | Roles holding it (of 10) |
|---|---|
| `training.view` | every role — **10** (your own course history is the base case) |
| `assets.view` | every role — **10** (your own kit is the base case) |
| `training.expiry.view` | HR Admin, HR Executive, Payroll Admin, Super Admin — **4** |
| `assets.history.view` | HR Admin, HR Executive, Management, Super Admin — **4** |
| `training.create` | HR Admin, HR Executive, Super Admin — **3** |
| `training.update` | the same three — **3** |
| `training.manage` | the same three — **3** |
| `training.assign` | the same three — **3** |
| `training.complete` | the same three — **3** |
| `training.certificates.view` | the same three — **3** |
| `assets.create` | the same three — **3** |
| `assets.update` | the same three — **3** |
| `assets.manage` | the same three — **3** |
| `assets.assign` | the same three — **3** |
| `assets.return` | the same three — **3** |

Five edges of that matrix are the whole design:

- **Ten roles hold `training.view`, and that is a door — not a browsing
  right.** `Visibility::employeeTrainingsFor()` answers *your own rows* with
  `.view` alone and *the workforce's* only when `training.assign` or
  `training.complete` follows. So an Employee sees their own card, a Site
  Supervisor sees their own team's rows because they hold neither, and only
  HR sees the roster. `training.view` on ten roles means ten roles may ask
  "where do I stand?", not ten roles may ask about everybody.
- **`training.certificates.view` is a separate grant from `training.manage`,
  and the file route asks for it specifically.** A manager who may note that
  a report is *uncertified* is not thereby handed the certificate itself —
  and the asymmetry is deliberate: `training.manage` **does not** open
  `GET /employee-training/{training}/file`. The permission that does is held
  by exactly the three roles whose job includes reading somebody's
  competence paper.
- **`training.expiry.view` is the same shape as `documents.expiry.view`.**
  "Whose card is about to lapse across the whole company" is a different
  question from "show me my own", and Payroll Admin gets it for the reason
  Payroll Admin gets the document one: a lapsed safety card is a payroll
  fact. The rows behind it are still narrowed unless `training.manage`
  follows.
- **`assets.manage` is the door onto the pool *and* onto `purchase_cost`.**
  Without it `Visibility` narrows `assets.view` to the assets assigned to
  you, and `AssetResource` **omits** `purchase_cost` entirely. Finance,
  Management and every Employee hold `assets.view` and do not hold
  `assets.manage`, so the cost of a colleague's laptop is not readable by
  browsing their desk. Management is the one non-HR role holding
  `assets.history.view`, because a hand-over log is a control document, not
  a personal file.
- **`assets.assign` and `assets.return` are split from each other.** The
  act of giving something away and the act of taking it back are two
  different trust decisions, and a role that may do one should be
  expressible without the other — the same reason `training.complete` is
  split from `training.update`.

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
| `LeaveRequestPolicy` | list, read, create, **edit a draft only**, `submit`, `cancel`, `approve`/`reject` (needs `ApprovalWorkflowService` — see below), `uploadCertificate`, `viewCertificate` |
| `LeaveBalancePolicy` | `viewAny` (`leave.balance.view`), `update` (`leave.balance.manage`) |
| `LeaveTypePolicy` | read (`leave.view`) / configure (`leave.manage`) |
| `HolidayPolicy` | `viewAny` **unconditionally true**; `create`/`update` need `holidays.manage`; **no `delete`** — retire with `status=inactive` |
| `TimesheetPolicy` | list/read with `timesheets.view` under the shared visibility scope; **no `update`, no approval ability** |
| `OvertimeRequestPolicy` | list/read, create, edit a draft, `submit`, `cancel`, `approve`/`reject` |
| `ApprovalWorkflowPolicy` | read (`approvals.view`) / configure (`approvals.manage`) |
| `ExpensePolicy` | list/read (row-scoped), create, edit/submit/cancel a claim, `approve`/`reject`, and the receipt trio `viewReceipt` / `storeReceipt` / `deleteReceipt` |

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

**Implemented in Phase 6 ✅ — and one bug worth remembering.**

`LeaveRequestPolicy` and `OvertimeRequestPolicy` are **constructor-injected
with `ApprovalWorkflowService`**, because "may this actor approve?" is a
question about the chain's current step and cannot be answered by a
permission string alone. That collides with how Laravel resolves policies:
`Gate::callPolicyMethod()` builds the policy itself and **never passes extra
constructor parameters** — so an injected service arrives as `null` and any
use of it is a 500 in production and a passing test in development.

Both policies therefore resolve the service lazily from the container inside
the ability method. A test that exercises `approve()` end-to-end, not just
the happy `view()`, is what would have caught it.

**The class must be named after the model.** Laravel *guesses* the policy from
the model: `OvertimeRequest` → `OvertimeRequestPolicy`. A class named
`OvertimePolicy` for that model is never resolved, and the gate **silently
denies** — no exception, no log line, just an unexplained `403` on every
call. It happened in Phase 6; the file was renamed and the rule is now in
ARCHITECTURE §3.6: `app/Policies/{Model}Policy.php`, always.

Three rules the Phase 6 policies encode:

- **No self-approval, at the engine.** `ApprovalWorkflowService::authorize()`
  compares the actor against `$actor->employee?->id` and refuses even a
  Super Admin who also owns the request. It lives in the one place every
  transition passes, not in six controllers that could each forget it.
- **Only the current step may act.** The chain is materialised into
  `approval_records` at submit; approving a later step, or re-deciding one
  already decided, is `403`.
- **Policy says "who", service says "state".** A policy never mutates
  anything. Balances, chain advancement, `payroll_eligible` and LOP
  conversion are service concerns, so the same workflow serves leave and
  overtime without either policy growing a subject-specific `if`.

**Certificate routes carry no `permission:` middleware.** `POST`/`GET
/leave/{id}/certificate` are gated by `LeaveRequestPolicy::uploadCertificate`
— you may file for a request you may already read, and only while it can
still accept one. The upload itself is bounded by §4.2.

**Status:** ✅ Permission layer live (Phase 2) · ✅ Permission-gated routes since Phase 3 · ✅ Policies for the Phase 4 modules (Phase 4) · ✅ Attendance + site-visit policies (Phase 5) · ✅ Leave / balance / leave-type / holiday / timesheet / overtime / approval-workflow policies (Phase 6) · ✅ **Site activity report + daily site report policies with `App\Support\Visibility` row scoping (Phase 7)** · ✅ **Payroll / allowance / payroll-adjustment / loan / salary-certificate policies (Phase 8)** · ✅ **Expense + expense-receipt policies (`ExpensePolicy`, Phase 9)** · ⬜ Employee-document policies in their own phase

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

### 3.2a Expense policies — ✅ Phase 9

`ExpensePolicy` answers the same two questions every policy here answers —
*who* and never *state* — with three groups of rule:

- **Filing.** `viewAny` needs `expenses.view` and the rows are narrowed by
  `Visibility::expenseIsVisible`; `create` needs `expenses.create` **and** an
  employee record; `update`, `submit` and `cancel` are "yours, or anybody's
  with `expenses.manage`". Whether a claim may still be edited, submitted or
  withdrawn is `ExpenseService`'s `409` naming the state — the policy never
  hands "This action is unauthorized" to what is really "Only a draft claim
  can be edited."
- **Deciding.** `approve` — and `reject`, which reuses it — needs all four of:
  `expenses.approve`; the claim actually `pending` (**not** merely undecided —
  a decided claim is a `403` here and a `409` at the service); **not the
  caller's own claim**, whoever the chain resolved to; and
  `ApprovalWorkflowService::actorMatchesCurrent`, so the caller is the
  resolved approver of the *current* link. A supervisor's scope comes from
  `Visibility::expenseIsVisible` / `directReportIds`, which is why a Site
  Supervisor holding the grant is still refused on a claim whose line manager
  is somebody else — same role, same permission, one step to answer, not
  theirs. A refusal, additionally, demands a reason: `ActOnExpenseRequest`
  makes `remarks` required server-side, and Flutter mirrors it in
  `reject(id, {required String remarks})`, so an empty rejection never
  reaches the chain.
- **Evidence.** `viewReceipt` first requires the receipt to belong to the
  claim being named, then answers "own claim, or `expenses.receipts.view`
  **and** read access to that claim". `storeReceipt`/`deleteReceipt` mirror
  `update` — your own draft, or anybody's with `expenses.manage` — leaving
  the draft-only question to the service's `409`.

Reading fails closed to *your own*, exactly as payroll and loans do and
deliberately unlike leave and overtime: a claim against the company's money
gets the tighter answer (`Visibility::mayViewOthersExpenses()`), so
`expenses.view` alone is "my claims", never "everybody's".

**Status:** ✅ **Expense + expense-receipt policies live (Phase 9)** — one
policy file, ten abilities, row scope from `App\Support\Visibility`.

### 3.2b Document, onboarding & bank-account policies — ✅ Phase 10

Three new files plus two new abilities on `EmployeePolicy`, for **26
policies** in all. Every one of them reads row scope through
`App\Support\Visibility`, which is the only place the rule is written.

| Policy | Abilities | Row scope |
|---|---|---|
| `EmployeeDocumentPolicy` | `viewAny`, `view`, `create`, `update`, `viewFile`, `verify`, `reject`, `delete`, `expiryReport` | `mayViewOthersDocuments()` = `documents.view` **and** `documents.manage`; `mayFileDocumentsFor()` = `documents.create` **and** (your own, or `documents.manage`). Fail-closed to *your own rows* |
| `DocumentTypePolicy` | `viewAny`, `view` | `documents.view` — the catalogue a filer needs, never a configuration screen |
| `EmployeeOnboardingPolicy` | `viewAny`, `view`, `update`, `complete` | own record, or `onboarding.manage` |
| `EmployeePolicy` | **+ `viewBankAccount`, `updateBankAccount`** (existing file, two new abilities) | your own row, or `employees.manage`; **and these are the only gates on their two routes** |

Four things this slice does that are worth naming:

- **The file is gated exactly like the row.** `viewFile` is not "knows the
  id" — it asks `view` first, so a document you may not read is a document
  you may not download, and `404` rather than `403` when the id is not in
  your scope (a 403 would confirm the id exists).
- **`verify` / `reject` are their own abilities, not a rider on `update`.**
  Filing somebody's paperwork and *signing it off* are different acts, and
  nobody may sign off on their own: the policy refuses a self-verification
  on top of the three-role grant, so holding `documents.verify` is not enough
  to approve your own passport.
- **`expiryReport` is its own ability** because it is the one query that is
  about *everybody* rather than about a record — see §3.1b.
- **Bank details are policy-only by design.** The two routes carry no
  `permission:` middleware, so there is no coarse gate to bypass or to
  outgrow; `EmployeePolicy` is the whole answer, and it fails closed.

**Status:** ✅ **Document, onboarding and bank-account policies live
(Phase 10)** — three new files, two new abilities on `EmployeePolicy`,
row scope from `App\Support\Visibility`.

### 3.2c Training & asset policies — ✅ Phase 11

Four new files, for **30 policies** in all. Same rule as every slice since
Phase 4: each one reads row scope through `App\Support\Visibility`, which is
the only place the rule is written.

| Policy | Abilities | Row scope |
|---|---|---|
| `EmployeeTrainingPolicy` | `viewAny`, `view`, `create`, `update`, `complete`, `cancel`, `viewCertificate`, `expiryReport` | `employeeTrainingsFor()` = your own rows, or `training.assign` / `training.complete`. `viewCertificate` = **your own card**, or `training.certificates.view` — **`training.manage` is deliberately not a third door** |
| `TrainingProgramPolicy` | `viewAny`, `view`, `create`, `update`, `retire` | A catalogue is shared, not personal: `training.view` reads it, and `training.create` / `training.update` write it. `retire` is separate from `update` because taking a course off the shelf must not be confusable with correcting its spelling |
| `AssetPolicy` | `viewAny`, `view`, `create`, `update`, `assign`, `returnAsset`, `changeStatus` | `assetsFor()` = the assets you hold, or `assets.manage`. `assign` / `returnAsset` / `changeStatus` are three abilities rather than one `update`, because handing a laptop out, taking it back and writing it off are three different acts |
| `AssetAssignmentPolicy` | `viewAny`, `view`, `history` | the rows you are party to, or `assets.history.view`. `history` is its own ability because the cross-employee log is a different question from "what am I holding" |

**`TrainingType` and `AssetType` have no policy file at all, and that is a
decision rather than an omission.** They are read-only vocabularies:
`GET /training-types` and `GET /asset-types`
answer a table rather than a record, there is no row to own, and a policy
file with one `viewAny()` would be a place where a future `destroy()` could
be forgotten. The routes instead ask the permission directly —
`abort_unless($request->user()?->can('training.view') === true, 403)` —
which fails closed and cannot be forgotten, because it is the same line the
rest of the route already carries.

**Four things this slice does that are worth naming:**

- **The certificate's gate is not the row's gate.** `viewCertificate` asks
  two questions where `view` asks one: may this person see this enrolment,
  and may they read *this* card. `training.manage` satisfies the first and
  is deliberately withheld from the second, so correcting a date never
  becomes a way of reading everybody's competence paper.
- **A refusal is 409 with a sentence, never a 403 with a shrug.** The
  service refuses *states* — an asset already held, a transition the table
  does not allow, a person already holding a place on that course — and the
  policy refuses *people*. Conflating them would tell an HR coordinator
  they are "forbidden" when the truth is "that laptop is with somebody",
  and those two lead to different next actions.
- **`expiryReport` is its own ability**, as it is on the document policy:
  the one query in the slice that is about everybody.
- **Nothing in either policy decides a date.** Certificate expiry is derived
  by the model against configuration (§4.11), so an authorisation change can
  never quietly change when a card expires.

**Status:** ✅ **Training and asset policies live (Phase 11)** — four new
files, thirty policies in all, row scope from `App\Support\Visibility`.

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
├── reports/
└── expense-receipts/{expenseId}/{uuid}.{jpg|png|webp|pdf}  ← Phase 9
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
✅ certificates (§4.4) · ✅ **report photographs (§4.5) and the report PDF
(§4.6)** · ✅ **expense receipts (§4.8)** · ⬜ Phase 9 (employee documents) · ⬜ audit/security phase

### 4.4 Certificates — ✅ Phase 6 (medical documents)

The same shape as selfies, minus the re-encode and plus a document sniff:

| Layer | What it does | Why it exists |
|---|---|---|
| **`StoreLeaveCertificateRequest`** | `mimes:pdf,jpg,jpeg,png,webp` + `mimetypes:…` + `max:` from config | The declared type, the sniffed type and the size must all agree before anything is touched |
| **`App\Rules\CertificateContent`** | A PDF must contain `%PDF-` inside its first 1024 bytes; an image must survive `getimagesize()` | Closes "3 MB of garbage renamed `note.pdf`", as a field error on `certificate`, not a 500 later |
| **`SickCertificateStore`** | Mints `leave-certificates/{employeeId}/{uuid}.{ext}`, never reads the client's filename for storage | No path traversal, no collision, no client-chosen extension reaching disk; a bad extension falls back to `.pdf` |
| **Private disk (`local`)** | Reachable only through `GET /leave/{id}/certificate` behind `LeaveRequestPolicy::viewCertificate`, `Cache-Control: no-store` | No public URL, no directory listing, no path in any JSON response |
| **Replace-in-place** | A re-upload deletes the previous file in the same transaction | A superseded certificate must not sit on disk with nothing pointing at it |

**Why certificates are validated but not re-encoded**, unlike selfies: a scan
is evidence — a signature, a stamp, a date. Transcoding a PDF or JPEG a
second time risks degrading exactly the thing being proved, and unlike a
selfie this file is never rendered inline to a third party from a response
body. Validation is sufficient where sanitisation was not.

**Deliberately not added:** no `certificate.upload` permission. The right to
file is derived from the request itself (`uploadCertificate`), because the
question is never "may this role upload files" but "may this person answer
for *this* request before *this* deadline" — and a permission that could be
granted independently of both would be the wrong question.

**Audit logging is still not implemented** for reads of this file either —
§8/§13.

### 4.5 Report photographs — ✅ Phase 7 (site activity & daily reports)

Same private-storage spine as the selfie, because a report photograph faces
the same threat: a phone's JPEG arrives carrying the coordinates it was
taken at and the device that took it.

| Layer | What it does | Why it exists |
|---|---|---|
| **`StoresPrivateImages`** | One shared trait for the accept-list (`image/jpeg`, `image/png`, `image/webp`), the size ceiling and the response headers | The selfie and the report photo must not drift into two different definitions of "acceptable image" |
| **`ReportPhotoStore`** | Injects `SelfieSanitizer` and re-encodes every frame; mints `site-report-photos/{activity\|daily}/{reportId}/{uuid}.jpg` | The client's filename never reaches disk, and neither does any EXIF/GPS block the phone wrote |
| **Fail-closed** | An image that cannot be decoded and re-encoded is refused at the request (`422` on `photos`), never stored as-sent | "Sanitisation failed, so keep the original" would make sanitisation optional |
| **Private disk (`local`)** | Reachable only through `GET …/{report}/photos/{photo}` behind the report's own policy, `no-store` | No public URL, no directory listing, no path and no `disk` key in any JSON |
| **`SiteReportPhotoResource`** | `{id, caption, sort_order, mime_type, size_bytes, created_at}` only | The row is metadata about a file; handing back its address would make the storage rule a convention |
| **Row + state checks** | Photo/report id mismatch → `404`; a photo attached to a filed report → `409` | "Exists somewhere" is not the same question as "belongs to the report you named" |
| **Batch limits** | 6 per request, 12 per report, and a partial failure unwinds every file it already wrote | A half-uploaded gallery is worse than none, because the report looks complete |

**Why re-encoded here and only validated for certificates (§4.4):** a report
photograph is not a signed document, it is a picture of a site condition —
and its embedded coordinates would *contradict* the fix the server records at
submit. Stripping them is the point. A certificate's signature is the
evidence, so it is left alone.

**No `Image.network` anywhere in the app.** A bare URL would have to be
public to be fetchable by one; instead the bytes are fetched through the
authenticated `ApiClient` and drawn from memory.

### 4.6 The daily report PDF — ✅ Phase 7

The document is built by the server from the row it is asked for, streamed
as `application/pdf` with `Cache-Control: no-store`, `Pragma: no-cache` and
`X-Content-Type-Options: nosniff`, and **written nowhere**. There is no
stored PDF, no `pdf_path` column and no signed URL to expire, so the only
way to a document is the route, which asks
`permission:daily_site_reports.pdf` and then `DailySiteReportPolicy::pdf`.

`.pdf` is a permission of its own rather than a rider on `.view`, and it is
**not** held by `Employee` — reading the numbers on a screen and being
handed something you can forward are different acts.

Photographs are embedded as `data:` URIs (capped at six) rather than
referenced, so rendering the file never has to expose or write an image.

**Audit logging is still not implemented** for reads of this document —
§8/§13.

### 4.7 Salary slips and salary certificates — ✅ Phase 8

Two documents, one rule: **the money is read from the row at the moment it is
asked for, and never written to disk.**

| Layer | What it does | Why it exists |
|---|---|---|
| `GET /salary-slips/{payroll}/pdf` | Renders the `payrolls` row, streams `application/pdf`, `Cache-Control: no-store` | There is no `salary_slips` table, no stored file and no URL — the slip *is* the row, so it cannot go stale behind a recalculation or be found by guessing a path |
| `GET /salary-certificate-requests/{id}/pdf` | Renders, streams, then calls `markGenerated()` **after** a successful write | A failed render leaves the row `approved` and answerable again rather than `generated` with nothing behind it; `generated_at` moves **once** |
| `SalarySlipPdf` / `SalaryCertificatePdf` | Same dompdf instance as §4.6 — no second PDF package to secure or upgrade | One dependency, one set of headers, one place a document is built |
| Gates | `salary_slips.view` + row scope; `salary_certificates.view` + `SalaryCertificateRequestPolicy` | Both are permission *and* policy: the grant says "may you read payslips", the policy says "may you read *this* one" |
| Response headers | `no-store`, `Pragma: no-cache`, `nosniff`, `Content-Disposition` with a **server-minted** filename | The filename is `safePdfFilename()`-shaped on the client and generated on the server — a caller cannot set the name a browser saves under |

**Nothing about salary appears anywhere it is not asked for.** `PayrollResource`
is the only shape that carries `net_salary`; it is not reachable from an
employee resource, from the summary endpoint (`payroll.summary.view` returns
counts and totals with **no names**), or from a log line — Phase 8 added no
`Log::info()` in any payroll path, because "which employee earned what" is
the one line a log file should never contain.

**Audit logging is still not implemented** for these reads — §8/§13.

### 4.8 Expense receipts — ✅ Phase 9

Evidence behind a claim for money gets the certificate's shape (§4.4) rather
than the selfie's: validated, never re-encoded, on the private disk,
reachable only through its own policy-checked route.

| Layer | What it does | Why it exists |
|---|---|---|
| `StoreExpenseReceiptsRequest` | `mimes` + `mimetypes` + `max` read from `HRMS_EXPENSE_RECEIPT_MAX_KB` (5120 KB) **plus** the shared `CertificateContent` content rule; 1–6 files per batch | JPEG/PNG/PDF validated on MIME, extension, size **and** content — `mimes` and `mimetypes` both read the *name*, so three kilobytes of HTML named `note.pdf` passes both and is refused by the content rule underneath them |
| `ExpenseReceiptStore` | the **only writer**; mints `expense-receipts/{expenseId}/{uuid}.{ext}` on the `local` disk and re-runs the same accept-check before writing | The client's filename — and anything path-like inside it — is discarded, so `../../evil.php` cannot be expressed even if validation were bypassed; a storage layer that trusts a validation layer is waiting for an upload bug |
| `ExpenseReceiptResource` | `id, expense_id, original_name, mime_type, size_bytes, is_image, is_pdf, url, uploaded_by, created_at` — and nothing else | **No storage path and no file URL ever leaves the API.** `url` is the id-based route `/api/v1/expenses/{e}/receipts/{r}`, not a link to a file; `uploaded_by` is the **user** id, not an employee row |
| The read route | `GET /api/v1/expenses/{expense}/receipts/{receipt}` behind `ExpensePolicy::viewReceipt`, `Cache-Control: no-store`, `Content-Disposition: attachment` | The bytes are fetched through that policy-checked route — own claim, or `expenses.receipts.view` *and* read access to the claim — and served as a download a browser does not render inline next to somebody else's session |
| Ceilings | 5120 KB per file, 6 per request, **10 per claim** | A claim is a folder with a lid: the byte ceiling bounds one request, the count bounds what a single claim can end up carrying |
| Database | row metadata only — **no file bytes in any column** | The row is a note about a file; storing the file would put the disclosure question in a place no policy visits |
| Logging | no path, no filename and no receipt content in any log line | Same rule as salary (§4.7): a receipt is a named person's invoice, and "who uploaded what" belongs in the audit phase (§8), not in a log file today |

Two things this section is careful **not** to say: there is no public URL
and no signed URL (a signed URL is a bearer secret with a lifetime, and it
would put the storage layout into a link that outlives the permission that
issued it), and nothing is re-encoded — unlike a selfie, a receipt must stay
the document it was, because a re-encoded PDF stops opening. What *is*
stripped is the client's filename, the one piece of metadata this app itself
creates.

Receipts are never committed to Git: they live under
`backend/storage/app/private/`, and the root `.gitignore` ignores
`/backend/storage/app/private/*` outright, alongside everything in §9.

**Audit logging is still not implemented** for reads of these files —
§8/§13.

### 4.9 Employee documents & the expiry answer — ✅ Phase 10

The most sensitive file the product holds, and the only one whose *age* is
also a decision.

**The file.** `EmployeeDocumentStore` is the only writer and the only
reader:

- **private** `local` disk (`storage/app/private`), `employee-documents/`
  (config `hrms.storage.document_directory`), so there is no `/storage/…`
  route and no directory listing;
- **unnameable** — `{employeeId}/{uuid}.{ext}`. The client's filename, and
  anything path-like inside it, is discarded, so `../../evil.php` cannot be
  expressed even if validation were bypassed;
- **validated five ways**: an allow-listed extension (`pdf, jpg, jpeg, png,
  webp`), a `finfo` MIME sniff of the real bytes (never the part's
  `Content-Type`), the byte ceiling
  (`hrms.storage.document_max_kilobytes`, default 10240 KB), content
  (`%PDF-` inside the first kilobyte for a PDF; a real decode plus a pixel
  budget for an image) — and the same checks repeated in the storage layer,
  because a storage layer that trusts a validation layer it may one day stop
  sharing an author with is waiting for an upload bug;
- **re-encoded, for images**: the same fail-closed `SelfieSanitizer` the
  attendance selfie goes through, so EXIF GPS, camera body, software string
  and any embedded thumbnail cannot survive a trip through a pixel buffer,
  and what lands is whatever GD produced — which cannot also be a script.
  Nothing is downsampled for its own sake;
- **byte-identical, for PDFs**: a document that is not the one that was
  filed stops being a document anybody can open, so nothing is stripped and
  the defence is the sniff, the uuid name, and `nosniff` on serve;
- **unexposed**: no payload in this API contains a path. The resource
  reports `has_file`, `original_name`, `mime_type`, `file_size` and
  `file_url`, and `file_url` is `/api/v1/employee-documents/{id}/file`;
- **served** as a `StreamedResponse` with `Content-Disposition: attachment`,
  `Cache-Control: no-store…` and `X-Content-Type-Options: nosniff`, under a
  server-minted name: every byte outside `[A-Za-z0-9 _-]` is dropped from
  the client's `original_name` (removing the CR, LF and `"` that would let a
  caller close the disposition attribute and append a header of their own);
- **row-scoped like the row**: `viewFile` asks `view` first, so an
  out-of-scope id answers `404` rather than `403` — a 403 would confirm the
  id exists;
- **never destroyed by archive**: `remove()` runs only when a document is
  *replaced*, so the superseded bytes do not linger unpointed-at.
  `DELETE /employee-documents/{document}` exists only for a row that was
  never verified; archiving takes a document out of the active list and
  leaves the row and its file on the employment file.

Documents are never committed to Git either: they live under
`backend/storage/app/private/`, ignored by the root `.gitignore` exactly as
receipts and selfies are.

**The expiry answer.** `expiry_state` (`none | valid | expiring_soon |
expired`) and `days_until_expiry` are computed **on the server** from the
date against *the type's own* `expiry_warning_days` (falling back to
`hrms.expiry.default_warning_days`, default 30), and shipped with every row.
No client derives them — a phone with the wrong clock still shows the
server's answer, and the answer the nightly job acts on is the answer the
screen drew. The `expired` list filter reads the **date**, not the stored
status, so a lagging scheduler cannot make the filter tell a lie;
`expiring_soon` computes the per-type window in SQL rather than from one
global constant.

`ScanDocumentExpiries` is scheduled at `hrms.expiry.scan_hour:6`,
`scan_minute:15` and writes `expiry_notified_at` **before** raising
`DocumentExpiring` / `DocumentExpired` — the marker is on the row, so a
second run in the same window changes nothing and raises nothing, and two
overlapping workers cannot both notify. The two events have no subscribers:
**no FCM exists**, and a notification hook is a listener rather than a
`->notify()` inside a loop.

**Audit logging is still not implemented** for reads of these files —
§8/§13.

### 4.10 Bank details — ✅ Phase 10

A different kind of secret from a document, and shaped like one:

- **1:1 side table** `employee_bank_accounts` with **every column on an
  `encrypted` cast** — `bank_name`, `account_holder_name`, `iban`,
  `account_number`, `swift_bic`;
- **absent from `EmployeeResource` and from every generic list by
  construction rather than by omission** — the columns are simply not on the
  model's arrayable surface for those resources, so the leak a forgotten
  `hidden()` causes cannot happen;
- **its own routes** `GET|PUT /employees/{employee}/bank-account` with **no
  `permission:` middleware at all**, gated only by
  `EmployeePolicy::viewBankAccount` / `updateBankAccount` (your own row, or
  `employees.manage`);
- **never logged**: a 422 from a bad field names the field and never echoes
  the value, and nothing in the slice writes an IBAN to a log line or an
  exception context;
- **not in Git** — it is database rows, and `APP_KEY` lives in `.env`
  behind §9.

> **⚠ `APP_KEY` must not be rotated casually.** Every column above is an
> encrypted cast. Rotating the key without first re-encrypting
> `employee_bank_accounts` turns every IBAN into unreadable bytes — the
> encryption is not reversible without the key it was written with. A
> rotation is a two-step operation (decrypt with the old key, re-encrypt
> with the new) and belongs in the deployment runbook, not in a routine
> `.env` refresh.

### 4.11 Training certificates, asset costs & the hand-over log — ✅ Phase 11

**The certificate does not get a new storage rule, and that is the control.**
It is uploaded through `EmployeeDocumentStore` — the same private disk, the
same six validation rules read from `ValidatesEmployeeDocuments`, the same
server-minted `employee-documents/{employeeId}/{uuid}.{ext}`, the same
bytes-follow extension, the same `StreamedResponse` with a
`[A-Za-z0-9 _-]` download name — because a second storage rule invented for
one more PDF is how the first one eventually gets bypassed.

- **The path never leaves the API.** `EmployeeTrainingResource` reports
  `has_certificate`, `certificate_original_name`, `certificate_mime_type`,
  `certificate_size` and `certificate_file_url`; `certificate_path` is not
  in any payload and there is no `/storage` route to it.
- **The file's gate is not the row's gate.**
  `GET /employee-training/{training}/file` runs `training.view` as the
  coarse door and then `EmployeeTrainingPolicy::viewCertificate` per row:
  **your own card**, or `training.certificates.view`. **`training.manage`
  does not open it** — the right to correct an enrolment date is not the
  right to read everybody's competence paper — and `Visibility::maySeeCertificateFor()`
  is where that asymmetry is written once.
- **Replaced, not accumulated.** Completing an already-completed row with a
  new file calls `store()`'s `remove()` on the old bytes first, so a
  superseded card does not sit on the disk with nothing pointing at it.
- **The completion payload refuses what the server owns.** `status`,
  `expiry_notified_at`, `created_by`, `employee_id` and
  `training_program_id` are all `prohibited`, so no client can walk its own
  enrolment to `completed`, backdate the window it will later be graded
  against, or re-attribute somebody else's record.
- **Nothing about a card is inferred on the client.**
  `certificate_expiry_state` (`none | valid | expiring_soon | expired`) and
  `days_until_expiry` are derived server-side by
  `EmployeeTraining::certificateExpiryState()` against
  `hrms.expiry.default_warning_days` (30, `HRMS_DOCUMENT_WARNING_DAYS`), and
  the Flutter screen renders that answer rather than recomputing it. Nothing
  stores a *state* that can drift.

**The nightly scan still cannot notify twice.** `ScanTrainingExpiries`
(daily 06:20, `withoutOverlapping()`, `onOneServer()`, `ShouldBeUnique`,
`$tries = 1`) raises `EmployeeTrainingExpiring` / `EmployeeTrainingExpired`
once per record per window through `expiry_notified_at`, exactly as the
document scan does — and **delivers nothing**: there is no FCM subscriber on
these events, which is the same deliberate absence Phase 10 documented.

**An asset's cost is withheld rather than hidden.** `purchase_cost`
(`decimal(12,2)`) is **omitted from `AssetResource` unless the reader holds
`assets.manage`**. An absent key means "not your business"; a `null` would
mean "it was free" — and a client that rendered `null` as `AED 0.00` would
be telling a lie about a finance figure. Flutter records which arrived
(`costVisible = json.containsKey('purchase_cost')`) and prints *Not shown to
your role* rather than *Not recorded*.

**The hand-over log is read-only, and only through one door.**
`GET /asset-assignments` needs `assets.view` **and** is narrowed by
`assets.history.view` for any row you are not party to. There is **no
`POST /asset-assignments`**: a row is created only by
`POST /assets/{asset}/assign`, so there is no second door that could be
forgotten. And `asset_assignments.remarks` is **replaced** by a return's
remarks and left standing when a return carries none — nobody can erase
what was said at hand-over time by being quiet at return time.

> **⚠ A hand-over's remarks are not sensitive in the `encrypted` sense and
> are deliberately not encrypted.** They are ordinary text on an ordinary
> column, gated by `assets.history.view`, because the threat this slice
> defends against is *who may read the log*, not *who may read the disk* —
> and a column that cannot be queried cannot be reported on either.

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
| A report naming its own author | No `employee_id` or `created_by` key is read from any Phase 7 request; both are taken from the bearer token. `project_id` is accepted only when it owns the stated `site_id`, and the service writes `$site->project_id` anyway |
| A report un-filing itself | There is no `status` key in any report body. `draft → submitted` is a route, and editing a filed report answers `409` |
| A fix that cannot exist | `latitude`/`longitude`/`gps_accuracy` are all-or-nothing, `Geo::isValidCoordinate` rejects `(0,0)` and non-finite values, and the ceiling reuses `hrms.attendance.max_gps_accuracy_metres` |
| An expense figure, currency, date or category ceiling taken on trust | `StoreExpenseRequest` re-validates `amount` (`numeric`, `> 0`, `<= 99999999.99`), `currency` (`size:3`, alpha) and `expense_date` (`before_or_equal:today`), and the service re-reads the category's `maximum_amount`; `employee_id` and `status` are **`prohibited`**, so "file one for a colleague" answers `422` rather than being quietly ignored |

**Status:** ✅ Auth endpoints since Phase 3 (`LoginRequest`, `ChangePasswordRequest`,
`PasswordResetRequest`), mass assignment closed on every model · ✅ Phase 4 write
endpoints each have their own Form Request (`StoreDepartmentRequest`,
`UpdateDepartmentRequest`, `StoreDesignationRequest`, `UpdateDesignationRequest`,
`StoreEmployeeRequest`, `UpdateEmployeeRequest`, `StoreProjectRequest`,
`UpdateProjectRequest`, `StoreSiteRequest`, `UpdateSiteRequest`,
`StoreEmployeeSiteAssignmentRequest`, `UpdateEmployeeSiteAssignmentRequest`) ·
✅ Phase 5 writes (`StoreCheckInRequest`, `StoreCheckOutRequest`,
`StartSiteVisitRequest`, `EndSiteVisitRequest`) ·
✅ **Phase 7 writes (`StoreSiteActivityReportRequest`,
`UpdateSiteActivityReportRequest`, `StoreDailySiteReportRequest`,
`UpdateDailySiteReportRequest`, `SubmitSiteActivityReportRequest`,
`SubmitDailySiteReportRequest` and `StoreReportPhotosRequest` — seven in all,
backed by the shared `ValidatesReportSite` and `ValidatesReportGps`
concerns)** ·
✅ **Phase 8 writes (`ProcessPayrollRequest`, `Store/UpdateAllowanceRequest`,
`Store/UpdatePayrollAdjustmentRequest`, `ActOnPayrollAdjustment`,
`Store/UpdateLoanRequest`, `ActOnLoan`, `Store/ActOnSalaryCertificateRequest`
— eleven in all)** ·
✅ **Phase 9 writes (`StoreExpenseRequest`, `UpdateExpenseRequest`,
`ActOnExpenseRequest`, `StoreExpenseReceiptsRequest` — four in all)** ·
⬜ one per write endpoint as later modules land

The Phase 7 set is worth naming for one habit it establishes: **the update
requests are not the store requests with everything made optional.**
`UpdateSiteActivityReportRequest` relaxes only the keys that may legitimately
change on an existing row and leaves the cross-field rules alone, so an edit
cannot smuggle in a combination a create would have refused.

Phase 8 applies the same habit to the one field that identifies a person.
`StoreLoanRequest` **requires** `employee_id` (defaulting to the caller's own
record in `prepareForValidation()` when absent, so an employee asking for an
advance never has to know their id), and `UpdateLoanRequest` **prohibits**
`employee_id` outright — a loan may be edited, but it may never be pointed at
a different borrower. No request in the family accepts a *figure* to
calculate with: amounts, statuses and balances arrive from the server, and
`ProcessPayrollRequest` accepts only the `year` and `month` to run. A client
that sent `net_salary` would have it ignored, which is the correct answer for
a field nobody should be able to assert.

`UpdateEmployeeSiteAssignmentRequest` is worth naming: it accepts **only**
`status` ∈ `{ended, cancelled}` and `end_date`. Identity fields
(`employee_id`, `site_id`, …) come back as per-field 422s, so an assignment
cannot be pointed at a different person or place by an edit.
`StoreEmployeeRequest` rejects `photo_path` and `user_id` outright — neither
is a client-supplied field.

**Server-side money validation — ✅ Phase 9.** The figure a client sends is
a suggestion. `StoreExpenseRequest` re-validates amount, currency and date,
and the category ceiling is re-read from `expense_categories` on create, on
update **and again at submit** — so lowering a ceiling after a draft was
written still refuses that draft's amount (`422` on `amount`, status left
`draft`) rather than grandfathering what was typed when the rule was looser.
Money is a decimal string end to end: `DECIMAL(12,2)` and
`App\Support\Money`, never a float or a double, and `100.999` lands as
`'101.00'` because the column, not the payload, is the record. Identity and
lifecycle stay on the server too: the claimant always comes from the
authenticated user, and `employee_id` and `status` are **`prohibited`** in
`StoreExpenseRequest` — a `422` with an `errors` map, not a silently dropped
key the client believes it set. Nothing in the slice writes a financial
figure or a receipt's contents to a log line (§4.7's rule, applied).

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
| Document / onboarding / bank-account writes (8 routes) | none | — | ✅ Phase 10, **deliberately** |
| General API | 60 / min | client IP | ⬜ Planned |
| Exports (PDF/Excel) | 10 / min | client IP | ⬜ Phase 12 |

**Phase 6 added no limiter, deliberately (also API_DOCUMENTATION §3).** Leave,
overtime, holiday and timesheet writes are cheap and already behind
`auth:sanctum` + a permission + a policy; throttling them would punish a user
retrying over a patchy site connection, which is this product's normal
condition rather than an attack. The one write that could be abused —
certificate upload — is bounded instead by the byte ceiling, the extension and
sniffed-type checks and the content rule in §4.2/§4.4. Rate limiting is a
control against *repetition*; there is nothing here worth repeating.

**Phase 7 added no limiter either, for the same reason and one extra
bound.** Report writes are a handful of fields behind two gates, and a
report is a *record*, not an expense — a flood of them is visible on the
list rather than being a cost paid silently. The upload is the only
resource a client can actually consume, and it is capped structurally: 6
files per request, 12 per report, 5120 KB each, and a partial batch is
unwound (§4.5). Adding a limiter later is a config line; making an
over-eager one permanent is not.

**Phase 8 added no limiter either — deliberately, and with one eye open.**
None of the 36 payroll-family routes accepts a credential, a file or an
unbounded body, and every one is behind `auth:sanctum` + a permission + a
policy. The single expensive call is `POST /payroll/process`, which prices a
month across every employee; it is bounded instead by *who may call it*
(`payroll.process`: Super Admin, HR Admin, Payroll Admin — three roles, and
none of them is a device in a field) and by the one-way ladder, which makes
a repeat call cost `updated: 0` rather than a second recalculation. That is
the shape the general API limiter should eventually take: a config line in
`config/rate_limiting.php`, not a permanent refusal.

**Phase 9 added no limiter either — deliberately.** None of the 13 new
`expense` routes carries a `throttle:` (verified: the only limiters on
`routes/api.php` remain `login`, `password_reset` and `attendance`), and
every one is behind `auth:sanctum` + a permission + `ExpensePolicy`. The one
resource a client can actually consume is the upload, and it is bounded
structurally: 5120 KB per file, 6 per request, 10 per claim (§4.8). The
decision routes are bounded by *who may call them* — `expenses.approve` is
held by seven roles, `expenses.manage` by four — and by the state machine,
where a repeated approve costs a `403` and a second write never happens
(§3.1a, §3.2a).

**Phase 10 added no limiter either — deliberately.** None of the eight new
write routes (five document, two onboarding, one bank account) carries a
`throttle:`; the coarse gates are `auth:sanctum` + `permission:` on six of
them, and the two bank-account routes are **policy-only by design** (§4.10),
which answers `403` before a query rather than after a burst. Every one also
sits behind a policy, and none accepts an unbounded body. The single
resource a client can consume is `POST /employee-documents`, bounded
structurally: one file, `PDF/JPEG/PNG/WebP`, 10240 KB (§4.9), five-way
validated — and every upload **leaves a row behind**, so abuse is a query
rather than a guess. The verification routes are bounded by *who may call
them*: `documents.verify` is held by three roles, `documents.manage` by the
same three, and nobody may sign off their own paperwork on top of that
(§3.1b). The nightly expiry scan is scheduled, not an endpoint, and its
idempotency marker lives on the row — there is nothing to repeat.

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

**Phase 11 made the same preparation for both new modules.** Every training
and asset write goes through `TrainingService`, `TrainingExpiryService` or
`AssetService` — a store, an enrol, a completion, a cancellation, an assign,
a return, a status change — so the future activity log is one call in
`AssetService::assign()` rather than one call in each of eighteen
controllers. The events the expiry jobs already raise
(`EmployeeTrainingExpiring` / `EmployeeTrainingExpired`) are the other half
of that readiness: the hook exists and the delivery does not.

The honest gaps: **attendance override is not audited because attendance
override does not exist yet**, and **login success/failure is still not
recorded anywhere** — see §2. Reading a selfie is authorised by
`viewSelfie`, but the read itself is not persisted as an activity row.

**Phase 6 added two more honest gaps.** An approval decision on a leave
request or an overtime claim, and an automatic LOP conversion, are exactly
the operations the table above says should be audited — and neither writes a
row yet. What Phase 6 *did* leave behind is the structure to audit later:
every decision already lands in `approval_records` (who, which step, when,
with what remark) and every LOP conversion records `lop_reason`,
`lop_applied_at` and dispatches `LeaveConvertedToLop` after commit. So the
facts of *what happened* are durable and queryable; what is missing is the
append-only *who-changed-it* log and the read trail, both of which stay in
the dedicated audit/security phase rather than being faked here.

**Phase 8 added a third, and refused to fake it.** "Payroll processing" is
in the table above and no activity row is written for it — nor is any row
fabricated out of `payroll_adjustments`-style columns to look like one. What
Phase 8 *did* do is make the future log one call deep: every payroll
mutation already runs inside a named service method
(`PayrollService::process/review/finalize/lock`, `LoanService::approve`,
`LoanService::claimInstallment`/`releaseInstallments`,
`SalaryCertificateService::approve`), each of which knows the actor, the row
and the transition, and each transition already stamps `reviewed_by`,
`processed_by`, `locked_by`, `approved_by` with their timestamps. Those
columns are *attribution*, not an audit trail — they tell you who last
touched a row, not who changed what across time — and the distinction is
recorded here rather than blurred by shipping a table that only half the
writes visit.

**Phase 9 added a fourth, and refused to fake it, too.** "Expenses —
approval / rejection" sits in the table above and no activity row is written
when a claim is submitted, approved, refused or cancelled. What the slice
*did* leave is the same one-call-deep structure: every transition runs inside
a named `ExpenseService` method wrapped in a DB transaction, each decision is
already durable in `approval_records` (who, which step, when, with what
remark) for `ApprovalRecord::TYPE_EXPENSE`, and each row stamps
`submitted_at` / `approved_at` / `rejected_at` / `cancelled_at` plus
`final_approved_by`. Those columns are attribution, not an audit trail — and
no financial figure and no receipt content is logged anywhere in the slice
(§4.8) — so the append-only *who-changed-it* log and the read trail stay in
the dedicated audit/security phase (§13) rather than being half-shipped
here.

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
- **Nothing in `.env.example` is a secret.** Every HRMS key added by the
  operational hardening pass is a limit or a directory name — GPS accuracy
  ceiling, rate-limit counts, byte and pixel budgets, JPEG quality, storage
  sub-directory names, scheduler tick minute. `DB_PASSWORD`, `MAIL_PASSWORD`,
  `AWS_SECRET_ACCESS_KEY` and `REDIS_PASSWORD` stay empty, commented or `null`
  exactly as they were. Adding a key there is safe only while that stays true.

**Status:** ✅ `.gitignore` in place · ✅ `.env.example` with placeholders only —
including the Phase 3 keys (`PASSWORD_RESET_ENABLED`, `LOGIN_RATE_LIMIT_*`,
`PASSWORD_RESET_RATE_LIMIT_*`, `BCRYPT_ROUNDS`), the Phase 5/6 storage keys
(`HRMS_SELFIE_*`, `HRMS_CERTIFICATE_*`, `GEOFENCE_*_METRES`,
`ATTENDANCE_*`) and the scheduler mechanics (`HRMS_SICK_TICK_MINUTE`,
`HRMS_SICK_OVERLAP_MINUTES`) — none of which carry credentials

---

## 10. Device & App Security (Flutter)

| Control | Detail | Status |
|---|---|---|
| Token storage | `flutter_secure_storage` → Keychain / Keystore, single fixed key, never plain storage | ✅ Phase 3 |
| Base URL | Baked in with `--dart-define` — no runtime setting that could repoint the app at an attacker's server | ✅ Phase 3 |
| Credentials in UI | Password masked by default; never logged, never placed in `AuthState` | ✅ Phase 3 |
| Session invalidation | Any request presenting a rejected token drops the local session and returns to `/login` | ✅ Phase 3 |
| Screenshotting of sensitive screens | Consider `flutter_windowsecure` for salary screens | ⬜ Phase 12 |
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
- Site activity reports ✅ **(Phase 7)** — one reading at submit, taken on
  demand and sent with the submit request

**Explicitly NOT implemented:**

- ❌ Continuous background GPS tracking — no background permission is
  declared, nothing polls, nothing uploads when the app is idle. The
  report form holds its fix in memory for as long as the form is open and
  asks for it again only when the person presses *Take reading* or
  *Submit*
- ❌ Location monitoring when the app is idle
- ❌ Facial recognition on selfies — the photograph is evidence read by a
  person during a dispute, never scored by a model
- ❌ A location history. There is nowhere to store one: a coordinate is
  written only when someone presses CHECK IN, START VISIT, END VISIT,
  CHECK OUT or **Submit report**, and every pair of them has a column in
  §4's tables
- ❌ Coordinates inside a report photograph. Every frame is re-encoded
  server-side (§4.5), so whatever GPS block the phone wrote to the JPEG
  is gone by the time it is stored — the only location on a report is the
  one the server validated at submit

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

**Salary privacy ✅ Phase 8.** A salary is the second-most sensitive fact the
system holds about a person, and Phase 8 treats it as one:

- `net_salary` and every other figure appear **only** in `PayrollResource`,
  reached through `/payroll`, `/payroll/{id}` and `/salary-slips` — never in
  `EmployeeResource`, never in `/reports/*`, never in an error message
- `/payroll/summary` returns counts and totals with **no names**, so
  `payroll.summary.view` cannot become a de-anonymised payroll list
- no payroll path writes a log line containing a figure or an employee id
- the loan balance is visible to the borrower and to the roles that decided
  it — there is no route that lists "who owes what" for curiosity's sake
- a certificate names the employee because a certificate is *about* them;
  it never contains a salary

**Status:** ✅ Phase 5 — the location and camera parts of this section are now
properties of shipped code, not a plan. **✅ Phase 7 — site activity reports
now collect a reading too, at submit, with no background permission.**
**✅ Phase 8 — salary minimisation above is shipped, not planned.**
The remaining rows (retention jobs) belong to later phases.

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
| 5 | Server-authoritative geofence (lat/lng range + accuracy ceiling + distance always computed, never accepted), site-assignment validation before any punch, private selfie storage behind `viewSelfie` with no-store and no paths in responses, MIME/extension/size checks twice over, `Attendance` + `SiteVisit` policies with fail-closed row scoping, `attendance.view` granted to `Employee` (167 → 168 grants at that point), `attendance` rate limiter (30/min per user) on the four writes, foreground-only location + camera permissions, offline queue with `client_event_id` idempotency and server re-validation on sync. **Not delivered: attendance override audit (no override exists) and login audit (§8) — both still outstanding** |
| 6 | **Leave, timesheets, overtime, holidays, approval workflows** — `permission:` middleware on every route except the three deliberately open ones (holiday reads, certificate read/write), 7 policies named after their models, no self-approval and current-step-only checks inside `ApprovalWorkflowService`, own-draft-only edits with all transitions through service methods, private certificate storage with three-way upload validation and no path in any response, an hourly idempotent scheduler job for the LOP conversion, 11 new permissions (51 total / 236 grants), `leave.request` retired, **deliberately no new rate limiter (§3)**. **Not delivered: approval and LOP audit rows — still outstanding (§8)** |
| 7 | **Site activity reports & the official daily report** — 18 routes each behind `permission:` **and** a policy; author (`employee_id` / `created_by`) and project derived server-side, never read from the payload; `project_id` must own the stated `site_id`; status only via `POST …/submit` with `409` on an edit to a filed report; `dsr_site_date_unique` plus request-level and service-level guards for one official report per site-day; report photographs re-encoded through the fail-closed `SelfieSanitizer`, private disk, no path and no URL in any response, 6 per request / 12 per report, mismatch → `404` and filed → `409`; the PDF rendered on demand, `no-store`, never stored, behind its own `daily_site_reports.pdf` permission — held by Super Admin,
HR Admin, HR Executive, Project Manager, Site Engineer, Site Supervisor and
Management, and by neither Payroll Admin, Finance nor `Employee`; 8 new permissions (59 total / 278 grants); submit-time GPS with a `(0,0)`/non-finite/accuracy-ceiling check; **deliberately no new rate limiter (§7)**. **Not delivered: report audit rows — still outstanding (§8)** |
| 8 | **Payroll, loans & salary documents** — 36 routes, each behind `permission:` **and** a policy; a one-way money ladder (`draft → calculated → reviewed → processed → locked`) with no reverse and no delete, so no permission authorises a capability the services refuse; `payroll.lock` held by **Payroll Admin alone**, `payroll.process` by three roles, `payroll.summary.view` returning totals with **no names**; every figure `DECIMAL(12,2)` and printed by one formatter (§5 of ARCHITECTURE), no float column anywhere; salary-slip and certificate PDFs rendered on demand behind `no-store` with **no stored file, no path and no URL** (§4.7); loan schedule minted at approval with a `payroll_id` on every installment it takes, so a run cannot take a payment twice; `employee_id` required on create and prohibited on update; salary never present in an employee resource or a log line; 11 new permissions (**70 total / 332 grants**), 5 new policies (22 total), 3 new settings (16 total); **deliberately no new rate limiter (§7)**. **Not delivered: payroll and salary-document audit rows — still outstanding (§8), and the UAE/statutory overtime rate is a generic multiplier, not a validated statutory configuration** |
| 9 | **Expense claims & receipts** — 13 new routes under `expense`, each behind `permission:` **and** `ExpensePolicy`; the claimant always from the authenticated user, with `employee_id` and `status` **`prohibited`** in `StoreExpenseRequest` (`422`, never silently ignored); statuses `draft → pending → approved \| rejected \| cancelled` reachable only through `ExpenseService`, every transition in a DB transaction, an illegal transition `409` and a field failure `422` with an `errors` map; money a decimal string end to end (`DECIMAL(12,2)`, `App\Support\Money`), amount `numeric`, `> 0`, `<= 99999999.99`, re-validated at the request **and** against the category ceiling at create, update and submit; the site must belong to the named project *and* to somewhere the claimant is placed; approval chain `ApprovalWorkflow::SUBJECT_EXPENSE` with seeded `EXP-STD` (step 1 `reporting_manager`, step 2 `permission: expenses.manage`), no self-approval, current-link-only, one decision per link; receipts on private storage at `expense-receipts/{expenseId}/{uuid}.{ext}` behind `viewReceipt`, MIME/extension/size/content validated, **no storage path and no file URL in any response** (`url` is the id-based route), 5120 KB and 10 per claim, `ExpenseReceiptStore` the only writer; 3 new permissions (**73 total / 364 grants**) and 1 new policy; **deliberately no new rate limiter (§7)**. **Not delivered: expense decision audit rows — still outstanding (§8)** |
| 10 | **Employee documents & onboarding** — 16 new routes; `employee-documents` behind `permission:` **and** `EmployeeDocumentPolicy` (row scope that fails closed to your own file, `employees.view` deliberately not a third door in), `document-types` behind `documents.view`, onboarding behind `onboarding.{view,manage}` with the row scope narrowing a non-manager to *themselves*, and the bank-account pair behind **no `permission:` at all** (`EmployeePolicy::{viewBankAccount,updateBankAccount}` is the only gate); `employee_id` on a store call answers `403` when the caller may neither file their own nor manage others'; uploads validated five ways (extension, `finfo` MIME, byte ceiling, content sniff, re-checked in the store), images **re-encoded through `SelfieSanitizer`** so no identity document keeps its EXIF GPS, PDFs stored byte-for-byte behind a `%PDF-` sniff, private disk at `employee-documents/{employeeId}/{uuid}.{ext}`, **no path in any response** (`file_url` is the id-based route), download `attachment` + `nosniff` + `no-store` + a `[A-Za-z0-9 _-]` filename; `expiry_state`/`days_until_expiry` **computed by the server per type window**, the `expired` filter driven by the date rather than the stored status, `ScanDocumentExpiries` scheduled and idempotent through `expiry_notified_at` with two events raised and **no FCM**; onboarding completion refused **409 naming what is outstanding**; `employee_bank_accounts` on `encrypted` casts outside every generic resource, **never logged**, with the `APP_KEY` rotation hazard documented (§4.10); 7 new permissions (**80 total / 401 grants**) with `documents.manage` + `onboarding.manage` held by exactly three roles, 3 new policies (26 total); **deliberately no new rate limiter (§7)**. **Training and assets were cut from this phase's approved scope. Not delivered: document and onboarding audit rows — still outstanding (§8)** |
| 11 | **Training & asset management** — 24 new routes, each behind `permission:` **and** a policy, with `TrainingType` / `AssetType` gated on the route alone (no policy file, `abort_unless` failing closed) and the two type endpoints answering a plain array; an enrolment ledger with **`UNIQUE (employee_id, training_program_id, enrollment_date)`** as well as two service pre-reads, so a duplicate is refused by the database *and* explained in a sentence; recertification is a new row, never a rewrite; `employee_training` payloads `prohibit` `status`, `expiry_notified_at`, `created_by`, `employee_id` and `training_program_id`; certificate bytes through the **existing** `EmployeeDocumentStore` behind `GET /employee-training/{training}/file`, gated by `viewCertificate` = **your own card or `training.certificates.view`, never `training.manage`** (§4.11), replaced rather than accumulated; certificate expiry `none\|valid\|expiring_soon\|expired` **derived server-side** against `hrms.expiry.default_warning_days`, `ScanTrainingExpiries` scheduled, idempotent through `expiry_notified_at`, two events raised and **no FCM**; `assets.status` a **projection** of the open `asset_assignments` row rather than a second opinion, `AssetService::changeStatus()` consulting the open-assignment rule **before** `Asset::TRANSITIONS`, and `assign()` holding a `lockForUpdate()` inside a transaction because "one open hand-over" cannot be a `UNIQUE` index; a return writes into the row the hand-over opened, its `remarks` replacing the original and a silent return leaving it standing; **no `POST /asset-assignments`** — one door into the table; `purchase_cost` **omitted, not nulled**, from `AssetResource` without `assets.manage` (§4.11); `Visibility::{employeeTrainingsFor, assetsFor}` failing closed to own rows, with compliance tallied **after** the filter; 15 new permissions (**95 total / 462 grants**) where `training.certificates.view` and `assets.manage` are held by exactly three roles, 4 new policies (30 total); **deliberately no new rate limiter (§7)**. **Not delivered: FCM delivery, the type CRUD screens and the audit rows for training and asset acts — all Phase 12 (§8)** |
| 12 | Notifications/FCM, full audit logging, dashboards, exports — planned |
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

### Payroll financial-safety hardening pass (after Phase 8)

Two things a payslip must never do — pay a negative net because of a
repayment, and silently drop a payment it could not take — fixed without
adding a single permission:

- `payroll.minimum_net_salary` (a **fourth** payroll setting, **17
  settings total**), default `0`, read through `SettingsService`. The floor
  is enforced inside `PayrollCalculationService`, where no route, payload or
  grant can reach it, and it is deliberately *not* grant-shaped: no role can
  switch it off by asking
- a run may take a loan or advance installment only up to
  `room = gross − (LOP + approved adjustments) − floor`, oldest due date
  first. Attendance and approved adjustments are never rewritten, so the
  floor cannot be used to cosmetically improve a slip
- `loan_installments.deducted_amount` plus a `partially_deducted` status
  record a partial claim; the per-run half lives on that run's own
  `payroll_items` lines (`source_type = loan_installment`), so releasing one
  month's recalculation gives back **that month's share** and cannot touch
  another's
- both balance mutators stay in `LoanService`, behind `lockForUpdate()`,
  clamped to what is still outstanding — the "never twice" guarantee now
  covers a *remainder* as well as a whole installment
- what did not fit stays outstanding and is offered to the next run together
  with anything overdue; the balance moves by what was **taken**, never by
  what was due
- locked rows cannot have a deduction re-cut: `recalculate` → 409, a re-run
  reports `skipped`, and retuning the floor afterwards changes nothing
  already signed off
- 1 migration (48 tables / 41 migrations), no new route, permission or
  policy, `PayrollRepaymentTest` (11 tests)

**Not delivered by this pass, unchanged:** UAE statutory deduction rules and
any statutory minimum-wage reading of the floor — both are settings a
deployment must validate for its own jurisdiction before production — and
audit logging (§8), which will attach to the same service methods this pass
kept all mutations behind.

---

## 14. Reporting a Security Issue

Do not open a public issue for a security vulnerability. Contact the maintainer
privately with reproduction steps.
