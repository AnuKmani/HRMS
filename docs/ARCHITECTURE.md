# Architecture

> **Status:** Phase 11 — RBAC, authentication, the business slices through
> attendance and leave, the site vertical slice (activity reports, the
> official daily report and its on-demand PDF), the payroll vertical slice
> (ledger, calculation, loans and salary documents), the expense vertical
> slice (claims, private receipts, the approval engine reused for a third
> subject), the employee-document and onboarding slice
> (configurable document types, private storage, server-computed expiry,
> the joiner checklist) and now **the training and asset slice**
> (two configurable vocabularies, an enrolment ledger with a certificate
> behind it, and an append-only hand-over history) are implemented and
> documented as built. Sections marked ⬜ are planned but not yet built.

---

## 1. System Overview

```
┌────────────────────────┐
│    Flutter Mobile App  │   Riverpod → Repository → Dio
│   (Android first)      │   Offline queue → SharedPreferences + files
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

**Implemented in Phase 5 ✅** — the geofence row in the table above is now a
real thing: `GeofenceService` (server) and `LocalGeofence` (Flutter) agree
on the arithmetic, but only `GeofenceService` decides. The request carries
`latitude`, `longitude` and `accuracy`; it never carries a distance, a
status or an `employee_id`, because there are no such fields in
`StoreCheckInRequest` to put them in.

### 2.2 Business rules are configurable, not hard-coded

Stored in a `settings` table and in entity configuration — working hours, grace period,
overtime threshold, geofence radius, sick-certificate deadline, leave entitlements,
carry-forward limits, approval workflows, notification timing.

**Implemented in Phase 5 ✅** — the schedule behind every check-in comes from
the site's active `shifts` row, falling back to the seeded
`working_hours.default`, `attendance.grace_period_minutes`,
`attendance.overtime_threshold_minutes` and
`attendance.default_geofence_radius`. There is no `08:00`, no `17:30` and no
`15` anywhere in `app/`.

### 2.3 Append-only where history matters

Site assignments, attendance overrides, approvals and payroll runs are **never overwritten**.
A new record is written with dates and an actor so the history stays auditable.

`attendances` and `site_visits` follow this: a check-out *adds* columns to
the row the check-in created; it never rewrites the arrival, the site or the
photograph.

### 2.4 Design for poor connectivity ✅ (Phase 5)

Construction sites lose signal. Attendance must queue locally, sync safely, and never
create duplicates.

Built as `OfflineQueue` + `OfflineQueueStore` in Flutter: one JSON blob of
events in `SharedPreferences`, selfies as files, statuses `pending_sync` /
`failed`, and a `client_event_id` generated before the first attempt so a
retry cannot become a second day.

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

**Implemented in Phase 8 ✅** — `app/Services/Payroll/` is the largest family yet and
the reason the controller layer stayed thin:

| Service | Owns |
|---|---|
| `PayrollCalculationService` | The whole derivation for one employee-month: basic → allowances → overtime → bonus → gross → LOP → unpaid leave → loan → net, including sizing each due installment against `payroll.minimum_net_salary` (**§5.14**). **No HTTP object reaches it** and it writes nothing |
| `PayrollService` | The transaction around a run: the unique-row insert, the one-way status ladder, the summary aggregation, and the **release → calculate → claim** order that makes a re-run give an installment back before it looks for one |
| `PayrollAdjustmentService` · `LoanService` | Approval transitions and their side effects — minting the schedule, `activateDue()` inside a pay run — plus the **only two mutators of `outstanding_balance`**: `claimInstallment(installment, payroll, amount)` and `releaseInstallments(payroll)`, both locked, both partial-capable |
| `SalaryCertificateService` | Ask / decide / cancel, and `markGenerated()` running **after** the PDF renders |
| `SalarySlipPdf` · `SalaryCertificatePdf` | dompdf rendering (`html()` separate from `response()` so content is testable) |
| `PayrollCalculation` · `PayrollPeriod` · `RepaymentAllocation` | Immutable value objects — the result, the year/month window, and one installment claim (scheduled / taken before / taken now / left) |

The rule the whole family obeys: **a controller never does arithmetic.** It
authorizes, calls one service method, and returns a resource — which is what
lets the same calculation serve a run, a recalculation and a summary without
any of the three disagreeing about a number.

**Implemented in Phase 9 ✅** — `app/Services/Expense/` is the smaller
sibling, and it obeys the same rule:

| Service | Owns |
|---|---|
| `ExpenseService` | Every write to `expenses` and `expense_receipts`, each transition in its own `DB::transaction`: create / update a draft, submit (which re-reads the category, the placement and the receipt folder, then starts the chain), approve / reject (settling only on the last link), cancel, and the receipt pair. Field-level failures are `ValidationException` keyed to the field the form is drawing; illegal **state** is `abort(409)` naming the state — "Only a draft claim can be edited", never a 403 that would say "unauthorized" about a caller who is allowed to try. It also holds `MAX_RECEIPTS` (10) and is the only writer of `status` |
| `ExpenseReceiptStore` | The private files: mints `expense-receipts/{expenseId}/{uuid}.{ext}` on the `local` disk, re-checks the type and size the FormRequest already checked, streams one back with `no-store`, and deletes on removal. No path ever enters a response — `ExpenseReceiptResource` reports `original_name`, `mime_type` and `size_bytes` instead |

The controller stays as thin as the payroll one: it authorizes, calls one
service method, and returns a resource.

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
their own records is the Policy's job. **Implemented in Phase 4 ✅**: six policies
(`Employee`, `Department`, `Designation`, `Project`, `Site`,
`EmployeeSiteAssignment`) are wired into every Phase 4 controller through a base
`Controller` using `AuthorizesRequests`. Never use a permission alone to decide
*which* rows to return.

**Implemented in Phase 5 ✅** — two more policies, `Attendance` and `SiteVisit`,
and one deliberate exception to the middleware rule:

| Route | Gate |
|---|---|
| `GET /attendance`, `GET /site-visits` | `permission:attendance.view` **then** policy row scope |
| `GET /attendance/today`, `POST /attendance/check-in`, `POST /attendance/check-out`, `GET /site-visits/today`, `POST /site-visits/start`, `POST /site-visits/{id}/end`, `GET /movement/today` | policy only — no `permission:` middleware |

Recording that you arrived is not a privilege anyone grants you, so an
employee with no attendance permission at all can still check themselves
in. What they cannot do is *read*: `AttendancePolicy::viewAny()` grants
`attendance.manage` or `employees.view`, or — for someone holding neither —
pins the query to their own employee id. `Visibility::attendanceFor()` does
the same scoping inside the query, and both are backed by
`config('hrms.visibility.attendance')`, which lists Project Manager, Site
Supervisor and Site Engineer as the roles that may see beyond their own
rows. There is no unscoped `attendance.manage` path: a PM is narrowed to the
projects where they are the `project_manager_id`, a Site Supervisor to the
sites where they are `site_manager_id` or `site_supervisor_id`.

The scoping is **fail-closed**: when neither of those two sets is non-empty,
the `WHERE EXISTS` clause that would have widened the rows is not applied at
all rather than applied without a predicate — a bug of exactly that shape
was found and fixed in Phase 5 while writing these tests.

Permissions added by this phase: `attendance.view` is now held by the
`Employee` role (168 grants, up from 167). `attendance.manage` is unchanged
— held by Super Admin, HR Admin, HR Executive and Site Supervisor, and still
withheld from Project Manager, Site Engineer, Payroll Admin, Finance,
Management and Employee, none of whom need to edit anybody's day to do their
job.

**Implemented in Phase 6 ✅** — seven more policies, the same two-layer split, and
two rules worth writing down:

| Route group | Gate |
|---|---|
| `GET /leave`, `GET /leave/{id}`, `GET /leave-types`, `GET /leave-types/{id}` | `permission:leave.view` **then** policy row scope |
| `POST /leave`, `PUT /leave/{id}`, `.../submit`, `.../cancel` | `permission:leave.create` **then** policy |
| `POST /leave/{id}/approve`, `POST /leave/{id}/reject` | `permission:leave.approve` **then** `LeaveRequestPolicy::approve` |
| `POST`/`GET` `/leave/{id}/certificate` | policy only — no `permission:` middleware |
| `GET /leave-balances`, `GET /leave-balances/{id}` | `permission:leave.balance.view` |
| `PUT /leave-balances/{id}` | `permission:leave.balance.manage` |
| `GET /holidays`, `GET /holidays/{id}` | **policy only** — `HolidayPolicy::viewAny()` is unconditionally `true` |
| `POST /holidays`, `PUT /holidays/{id}` | `permission:holidays.manage` |
| `GET /timesheets` (+`{id}`) | `permission:timesheets.view` **then** row scope |
| `POST /timesheets/generate` | `permission:timesheets.manage` |
| `GET /overtime` (+`{id}`) | `permission:overtime.view` **then** row scope |
| `POST /overtime`, `PUT /overtime/{id}`, `.../submit`, `.../cancel` | `permission:overtime.create` |
| `POST /overtime/{id}/approve`, `.../reject` | `permission:overtime.approve` **then** `OvertimeRequestPolicy::approve` |
| `GET/POST/PUT /approval-workflows` | `permission:approvals.view` / `approvals.manage` |

**Rule 1 — policy answers "who", service answers "state".**

A policy never mutates anything and never looks at workflow position beyond
"is this actor allowed to try". Everything about *what a decision does* —
advancing the chain, releasing the balance, writing `payroll_eligible`,
converting to LOP — lives in a service. That split is what makes the same
workflow usable by leave and overtime without a policy growing an `if`
per subject.

**Rule 2 — a policy class is always named after its model.**

Laravel's `Gate::callPolicyMethod()` *guesses* the policy from the model
class (`OvertimeRequest` → `OvertimeRequestPolicy`). A class named
`OvertimePolicy` for the `OvertimeRequest` model is never resolved, and the
gate **silently denies** every check — no exception, no 500, just an
unexplained `403`. This happened during Phase 6 and the fix was a rename, so
the rule is now: `app/Policies/{Model}Policy.php`, always, no shorthand.

**Rule 3 — self-approval is impossible, at the engine, not the route.**

`ApprovalWorkflowService::authorize()` compares the actor against
`$actor->employee?->id`. Even a Super Admin who also owns the request is
refused, and the check sits in the one place every transition passes through
rather than in six controllers that could each forget it.

**Implemented in Phase 7 ✅** — two more policies, `SiteActivityReportPolicy`
and `DailySiteReportPolicy`, and a **`App\Support\Visibility`** helper that
holds the row-scoping so the two policies and the two list queries cannot
drift apart:

| Route group | Gate |
|---|---|
| `GET /site-activity-reports` | `permission:site_activity_reports.view` **then** `siteActivityReportsFor()` |
| `GET /site-activity-reports/reportable-sites` | `permission:site_activity_reports.view` |
| `GET /site-activity-reports/{id}` | `permission:…view` **then** `SiteActivityReportPolicy::view` |
| `POST /site-activity-reports` | `permission:…create` **then** `create` → `mayReportAt(site)` |
| `PUT /site-activity-reports/{id}`, `.../submit` | `permission:…update` **then** `update`/`submit` |
| `POST`/`DELETE` `.../photos` | `permission:…update` **then** policy (404 on mismatch, 409 when filed) |
| `GET /daily-site-reports` | `permission:daily_site_reports.view` **then** `dailySiteReportsFor()` |
| `GET /daily-site-reports/{id}` | `permission:…view` **then** `DailySiteReportPolicy::view` |
| `POST /daily-site-reports` | `permission:…create` **then** `create` |
| `PUT /daily-site-reports/{id}`, `.../submit` | `permission:…update` **then** policy |
| `GET /daily-site-reports/{id}/pdf` | `permission:daily_site_reports.pdf` **then** `DailySiteReportPolicy::pdf` |

The scoping is coarse where it must be and closed by default where it can
be: `mayViewOthersSiteActivityReports()` and `mayViewOthersDailySiteReports()`
**return `false` for `Employee`**, so a permission string alone never decides
whose rows appear. An Employee sees their own activity reports; a Site
Supervisor sees the sites they run; a Project Manager sees the sites on the
projects they manage; HR, Management and Super Admin are gated by the
permission and nothing else.

The policies also deliberately answer `true` to `update` on a *filed* row.
The refusal a user actually meets is `409`, raised by
`assertReportIsEditable()` — "this is filed, and here is why that matters"
— rather than a `403`, which would say "you are not allowed to edit your own
report" and be untrue.

**Rule 4 — only the current step may act.**

The chain is materialised into `approval_records` at submit. Approve/reject
finds the single row for this subject in `waiting` status whose approver is
the actor, updates it, and either advances to the next step or finalises.
Approving on behalf of a later step, or re-deciding one already decided, is
`403` — and no self-service route can reach the endpoint without the coarse
permission too.

Reads are scoped by `Visibility` (`hrms.visibility.attendance` for leave,
timesheets and overtime — the same three roles that may see beyond their own
attendance), so a Project Manager's queue contains their reports and nobody
else's, in both the list query and the single-row check.

**Implemented in Phase 8 ✅** — five more policies (`Payroll`,
`PayrollAdjustment`, `Allowance`, `Loan`, `SalaryCertificateRequest`), 22 in
total, and `Visibility` gained the four helpers the payroll family reads:

| Route group | Gate |
|---|---|
| `GET /payroll`, `GET /payroll/{id}` | `permission:payroll.view` **then** `payrollsFor()` / `PayrollPolicy::view` |
| `GET /payroll/summary` | `permission:payroll.summary.view` — aggregates only, no names |
| `POST /payroll/process`, `POST /payroll/{id}/recalculate` | `permission:payroll.process` **then** policy |
| `POST /payroll/{id}/review`, `.../finalize` | `permission:payroll.manage` **then** policy |
| `POST /payroll/{id}/lock` | `permission:payroll.lock` **then** policy |
| `GET /salary-slips`, `GET /salary-slips/{id}/pdf` | `permission:salary_slips.view` **then** the same row scope as `/payroll` |
| `GET /allowances`, `GET /payroll-adjustments` | `permission:payroll.view` (own rows unless `payroll.manage`) |
| all writes on allowances and adjustments | `permission:payroll.manage` **then** policy |
| `GET /loans`, `GET /loans/{id}` | `permission:loans.view` **then** `loansFor()` — no `employees.view` fallback |
| `POST /loans`, `POST /loans/{id}/submit` | `permission:loans.create` **then** `LoanPolicy` |
| `POST /loans/{id}/approve`, `/reject` | `permission:loans.approve` **then** `LoanPolicy::approve` (self-approval is `403`) |
| `POST /loans/{id}/cancel`, `PUT /loans/{id}` | `permission:loans.view` **then** policy (draft only) |
| `GET/POST /salary-certificate-requests`, `GET .../{id}`, `.../pdf` | `permission:salary_certificates.view` **then** policy |
| `POST .../{id}/approve`, `/reject` | `permission:salary_certificates.manage` **then** policy (second decision is `403`) |

Two rules carry over and one is new. **"Who" and "state" stay apart** — a
policy that finds `isPending()` false answers `403`, and the service refuses
the same second decision with `409` as defence in depth, so the two layers
never disagree about the caller. **A refusal to act is `409`; a refusal of a
caller is `403`.** The new one: **nothing here recalculates a locked row** —
`PayrollPolicy` answers `false` for any transition past `locked`, and the
service refuses again, because a payroll number that moves after it has been
handed out is worse than a wrong one that stays put.

**Implemented in Phase 9 ✅** — the twenty-third policy, `ExpensePolicy`,
13 routes, and the same two-layer split pointed at money:

| Route group | Gate |
|---|---|
| `GET /expenses`, `GET /expenses/summary`, `GET /expenses/{id}` | `permission:expenses.view` **then** `expensesFor()` / `ExpensePolicy::view` |
| `GET /expense-categories` | `permission:expenses.view` — read-only by design; there is no POST/PUT/DELETE on categories |
| `POST /expenses` | `permission:expenses.create` **then** `create` |
| `PUT /expenses/{id}` | `permission:expenses.update` **then** `update` (own draft, or `expenses.manage`) |
| `POST /expenses/{id}/submit`, `.../cancel` | `permission:expenses.create` **then** `submit` / `cancel` |
| `POST /expenses/{id}/approve`, `.../reject` | `permission:expenses.approve` **then** `approve` / `reject` — pending, not your own, and you must be the resolved approver of the *current* link |
| `POST /expenses/{id}/receipts`, `GET` / `DELETE .../receipts/{receipt}` | `permission:expenses.view` **then** `storeReceipt` / `viewReceipt` / `deleteReceipt` |

The receipt routes are gated coarsely by `expenses.view` on purpose: the
door has to admit the person who filed the claim, and the fine rule is
`ExpensePolicy::viewReceipt()` — own claim, or `expenses.receipts.view` *and*
read access to that claim. Being shown a list of claims and being handed the
invoice behind one are different acts, so they are different permissions.

`Visibility` gained the expense helpers in the same shape as the families
before it: `expensesFor()` scopes the list, `expenseIsVisible()` is its
row-level twin so a `show` cannot answer "yes" to a claim the index hid,
`mayViewOthersExpenses()` fails **closed** (a scoped role, `expenses.manage`
or `expenses.approve` — deliberately *not* `employees.view`, because minutes
are defensible to open and money is not), `mayClaimExpenseAt()` answers
"may you book against this site?" on create, update and submit, and the
private `directReportIds(User)` keeps the line manager's own reports in view.
Management, holding only `expenses.view`, therefore reads its own claims and
nobody else's; an Employee's list is their own plus nothing; a Project
Manager's is their workforce plus the claims they are the
`reporting_manager` of — because EXP-STD's first link is a person, and
somebody has to be able to read what they are about to sign.

#### Roles and permissions

| | |
|---|---|
| Roles | 10 — Super Admin, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Engineer, Site Supervisor, Finance, Management, Employee |
| Permissions | **73**, all named `resource.action` (lowercase) |
| Grants | **364** rows in `role_has_permissions` |
| Seeders | `RoleSeeder` → `PermissionSeeder` → `RolePermissionSeeder` (order matters) |

Permission catalogue lives in one place — `PermissionSeeder::PERMISSIONS`, grouped by
module. Adding a module means adding a line there; nothing else needs the full list.

```
dashboard    dashboard.view
employees    employees.view | .create | .update | .delete | .salary.view
departments  departments.view | .manage
designations designations.view | .manage
attendance   attendance.view | .manage
approvals    approvals.view | .manage
leave        leave.view | .create | .approve | .manage | leave.balance.view | leave.balance.manage
holidays     holidays.manage
timesheets   timesheets.view | .manage
overtime     overtime.view | .create | .approve | .manage
payroll      payroll.view | .manage | .process | .lock | payroll.summary.view
salary_slips salary_slips.view | .manage
salary_certificates salary_certificates.view | .manage
loans        loans.view | .create | .approve | .manage
projects     projects.view | .manage
sites        sites.view | .manage
site_activity_reports site_activity_reports.view | .create | .update
daily_site_reports    daily_site_reports.view | .create | .update | .manage | .pdf
shifts       shifts.view | .manage
assignments  assignments.view | .manage
reports      reports.view | .export
documents    documents.view | .manage
expenses     expenses.view | .create | .update | .approve | .manage | .receipts.view
settings     settings.view | .manage
roles        roles.view | .manage
users        users.view | .manage
audit        audit.view
```

There is deliberately **no `holidays.view`**: reading the calendar is a
courtesy every signed-in account is owed (you cannot plan leave around days
you are not allowed to see), so `GET /holidays` carries no `permission:`
middleware at all and `HolidayPolicy::viewAny()` returns `true`. Writing one
is `holidays.manage`. Likewise there is no `timesheet.approve` — timesheets
are derived snapshots with nothing to approve (see §5.9).

`leave.request` was **retired** in Phase 6 in favour of `leave.create`,
joining the `resource.action` shape every other verb follows;
`PermissionSeeder::RETIRED` deletes it when the seeder re-runs, so an older
database does not keep a permission nothing references.

Phase 7 added eight in the same shape and one asymmetry worth stating:
`site_activity_reports.view` **includes** `Employee`, because filing your own
day is the point of the module, while `daily_site_reports.view` and
`.pdf` **exclude** `Employee` — the official record about a site-day is
prepared by the people who run the site. (`daily_site_reports.view`/`.pdf`
reach seven roles: Super Admin, HR Admin, HR Executive, Project Manager,
Site Engineer, Site Supervisor and Management; Payroll Admin and Finance
are out of it as well as `Employee`.) And `.pdf` is a permission of its
own rather than a rider on `.view`: reading the numbers and being handed a
document you can forward are different acts, so `DailySiteReportPolicy::pdf()`
asks for both this and the row-level question.

Phase 8 added eleven, three of them correcting an omission: `payroll.view`
and `payroll.manage` already existed, but neither said *who may press which
button*, so `payroll.process` (run and recalculate), `payroll.lock` (the
irreversible one, **Payroll Admin alone**) and `payroll.summary.view` (totals
and no rows — Management and Finance) split the difference. The rest follow
the module shape: `salary_slips.{view,manage}`, `salary_certificates.{view,manage}`,
`loans.{view,create,approve,manage}`. Two asymmetries are deliberate. First,
**`salary_slips.view` is not `payroll.view`** — a role can be given its
payslips without being given the ledger, and collapsing them would make one
grant meaningless, which is why `salary-slips` is its own route family.
Second, **asking is not a second grant**: `salary_certificates.view` is
enough to file for yourself, because an employee requesting a certificate of
their own employment is not an administrative act; deciding one is
`.manage`. `loans.approve` is withheld from `Employee`, and
`LoanPolicy::approve()` refuses a self-approval on top of the coarse gate —
the two answers differ on purpose, `403` for *who* and `409` for *state*.

Phase 9 added three to the `expenses` line above — `expenses.create`,
`expenses.update` and `expenses.receipts.view` — leaving the three that
already existed (`expenses.view`, `expenses.approve`, `expenses.manage`)
untouched: **73 permissions / 364 grants** after Phase 9. Two asymmetries are
deliberate, both visible in `RolePermissionSeeder`. First, **`.create` is
narrower than `.update`**: filing a claim goes to the seven roles expected
to file their own (Employee, HR Admin, HR Executive, Project Manager, Site
Engineer, Site Supervisor, Super Admin), while `.update` reaches nine —
Finance and Payroll Admin may correct a draft as the counterpart of the
`.manage` they already hold, without being handed a door they would never
use, because neither role files claims of its own. Second,
**`.receipts.view` is its own grant rather than a rider on `.view`**, held
by the seven who may read a claim they did not file: seeing a list of
claims and being handed the invoice behind one are different acts. Employee
is deliberately not among them — your own receipts read back through
ownership — and Site Engineer is excluded with it. `ExpensePolicy` then
asks the row-level question on top of each of the three, and nobody may
approve their own claim whatever the chain says.

**Phase 10 added seven** — six more `documents.*` verbs
(`documents.create`, `documents.update`, `documents.verify`,
`documents.delete`, `documents.expiry.view`, `documents.manage`; the seventh,
`documents.view`, already existed) and the whole new `onboarding` group
(`onboarding.view`, `onboarding.manage`) — leaving **80 permissions / 401
grants**. One asymmetry is the whole design of the slice, and it is visible
in `RolePermissionSeeder`: **`documents.manage` reaches exactly three roles**
(HR Admin, HR Executive, Super Admin) while `documents.view` reaches ten.
`Visibility::mayViewOthersDocuments()` requires **both** `documents.view`
*and* `documents.manage`, and `employees.view` is deliberately not a third
door in — so Project Manager, Site Supervisor and Site Engineer, who hold
`.view` + `.create` + `.update`, may file a colleague's paperwork without
thereby being able to open a colleague's passport, visa, medical record or
contract. The same three hold `onboarding.manage`; the other seven hold only
`onboarding.view`, which the row scope then narrows to *themselves*. Bank
details are gated again on top, by `EmployeePolicy::viewBankAccount` /
`updateBankAccount` on two routes that carry no `permission:` middleware at
all — there is no role whose job is to read everybody's IBAN.

**Phase 11 added fifteen** — eight `training.*` verbs
(`view`, `create`, `update`, `manage`, `assign`, `complete`,
`certificates.view`, `expiry.view`) and seven `assets.*` verbs (`view`,
`create`, `update`, `manage`, `assign`, `return`, `history.view`) — leaving
**95 permissions / 462 grants**. The slice's own asymmetry, visible in
`RolePermissionSeeder`: **all fifteen reach three roles** (HR Admin, HR
Executive, Super Admin) while `training.view` and `assets.view` reach ten.
Two edges matter more than the rest. `training.certificates.view` is
separate from `training.manage`, and the file route asks for it
specifically — holding the right to correct an enrolment date is not the
right to read somebody else's competence paper. And `assets.manage` is both
the row-scope gate and the only door onto `purchase_cost`, so Finance and
Management, who read the register, do not read what anything in it cost.

**Super Admin** holds `['*']` — every permission, resolved from the catalogue at seed
time rather than hard-coded, so a newly added permission is granted automatically.
Every other role is an explicit allow-list: anything absent is **denied**. The mapping
is `RolePermissionSeeder::MAP`, and `RbacTest` asserts both directions (Super Admin has
all 95; `Employee` is denied `payroll.manage`, `payroll.process`, `payroll.lock`, `employees.delete`, `attendance.manage`,
`leave.approve`, `audit.view`).

`employees.salary.view` — added in Phase 4 — is the one permission that is *not*
implied by its module: `employees.view` opens the roster, three segments more
are required before a payroll figure may be drawn. Held by HR Admin, Payroll
Admin, Finance (and Super Admin through `*`); deliberately withheld from HR
Executive, who maintains the roster without seeing what anyone is paid.

### 3.7 Phase 9 ✅ — Expense management

Thirteen routes behind one controller, four FormRequests, two services, one
policy — and no new engine. Every hard question a claim raises (who may read
it, who signs it, what the chain looks like, what a receipt is) was answered
somewhere in Phases 6–8; this slice reuses those answers instead of writing
a second copy of them. The route gates and the row-scoping are in §3.6; what
follows is how the slice itself is put together.

**The controller is thin.** `Api\V1\ExpenseController` authorizes, calls one
service method and returns a resource — nothing else. Its class docblock
states the rule: *the only controller that writes an expense claim, and it
writes none of it*. It owns two reads that are deliberately not CRUD:
`GET /expense-categories` (the pickable list plus each category's two rules —
there is **no POST/PUT/DELETE**, because a category is configuration and
adding one is a row, not an endpoint) and `GET /expenses/summary` (four
totals — by status, by category, by project, over a date range — scoped
through the same `expensesFor()` as the list, because a summary that
answered wider than the list would be the same hole wearing a different hat).

**The service is the state machine.** `ExpenseService` wraps every mutation
in a `DB::transaction` — create, update, submit, approve, reject, cancel,
attach receipts, remove one — and is the only writer of `status`. Two error
vocabularies, kept apart for the same reason as everywhere else:

- **A field is wrong → `ValidationException` (422 keyed to the field)** —
  the category's ceiling, "you may only claim against a project or site you
  are assigned to", "that site does not belong to the selected project", a
  category that demands evidence receiving a claim with none.
- **The state is wrong → `abort(409)` naming the state** — "Only a draft
  claim can be edited. Cancel it and file a new one." Never a 403, which
  would tell a caller who *is* allowed to try that they are not allowed.

Money travels as decimal strings through `App\Support\Money`, is rounded
with `Money::round()` before it reaches the column, and no money figure is
stored or handed back to a client as a float (**§5.13**).

#### The four FormRequests

| Request | Endpoint | What it owns |
|---|---|---|
| `StoreExpenseRequest` | `POST /expenses` | The claim's shape: a date no later than today (a future date is a plan, not an expense), an **active** category, `amount > 0` bounded by the `DECIMAL(12,2)` column, a three-letter currency, and the cross-field rule no `exists:` can express — a site must belong to the project named. `employee_id` and `status` are **`prohibited`, not ignored**: a payload that names a colleague or sets a status must fail loudly rather than be quietly believed |
| `UpdateExpenseRequest` | `PUT /expenses/{id}` | The same rules with everything `sometimes`, because an update names what changed rather than everything that is true; `employee_id` stays prohibited. Whether *this row* may still be edited is the service's 409, not a rule here |
| `ActOnExpenseRequest` | `…/submit`, `…/approve`, `…/reject`, `…/cancel` | One body for all four transitions: optional `remarks`, **required on reject only** — a refusal with no reason is a decision nobody can learn from, and the remark lands on the approval record that was refused, not on the claim. `authorize()` asks the policy *before* validation runs, so an out-of-chain approver is told the claim is none of their business before their body is examined |
| `StoreExpenseReceiptsRequest` | `POST /expenses/{id}/receipts` | One batch of 1–6 files: `mimes` + `mimetypes` + the configured byte ceiling + `CertificateContent` (the bytes are what they claim to be), with `authorize()` → `ExpensePolicy::storeReceipt` — your own draft, or anybody's with `expenses.manage`. Six per request is about one request's weight; the 10-per-claim ceiling is `ExpenseService`'s |

**`ExpensePolicy` answers "who", never "state".** Four groups of question:
the employee files, edits, submits and withdraws their own and may never
approve one (checked here *and* in the engine); an approver may act only
while the claim is pending, is not their own, and they are the resolved
approver of the current link; `expenses.manage` may push, withdraw and
correct without becoming an approver; and reading fails closed to your own.
`viewReceipt()` is the one rule that goes a step further than the claim that
carries it — own claim, or `expenses.receipts.view` *and* read access to
that claim. State questions are deliberately absent: refusing a non-draft
here would hand the caller "unauthorized" for what is really "already
submitted".

**The approval workflow is reused, not hard-coded.** `ApprovalWorkflow`
gained `SUBJECT_EXPENSE = 'expense'`, `ApprovalRecord` gained
`TYPE_EXPENSE = 'expense'` plus an `expense()` accessor, and
`ApprovalWorkflowService` widened its union from `LeaveRequest|OvertimeRequest`
to `LeaveRequest|OvertimeRequest|Expense` on every public method — that is
the entire integration. The engine still knows nothing about claims: it
walks steps, resolves reporting-manager / role / permission links, refuses
self-approval, freezes the chain at submit and advances
`current_approval_step`. `ExpenseService` decides only *when* a chain starts
(submit), *when* it is settled (the last link) and *when* it is abandoned
(cancel of a pending claim) — it never decides who approves, because that is
data in `approval_workflows`. The chain itself is the seeded `EXP-STD`
default for the subject: Supervisor (`reporting_manager`) → Finance / HR
(`permission: expenses.manage`), two links because money has a shorter
question than absence does. Everything Phase 6 proved — the chain is frozen
at submit, a later edit to the definition cannot re-route a claim in flight,
self-approval is impossible at the engine rather than at the route — holds
for expenses without a line of it being rewritten.

### 3.8 Phase 10 ✅ — Employee documents & onboarding

Five migrations, five models, three services, one store, four policies, two
events, one scheduled job — and, again, no new engine. What makes this slice
different is *where the rules live*: **a passport is a row, not a branch.**

**Configuration, not code.** `document_types` carries
`requires_document_number` / `requires_issue_date` / `requires_expiry_date`
and `expiry_warning_days`; `onboarding_requirements` carries `code`, `kind`
and the column list for a `data` requirement. Nothing in
`EmployeeDocumentService` or `OnboardingService` knows what a passport is,
which is why adding a document type is one insert and why the checklist and
the form ask for the same things without either of them being told to.

**Four services, each the only writer of one question.**

| | Owns | Does not own |
|---|---|---|
| `EmployeeDocumentService` | filing, replacing, verifying, rejecting, archiving — and the invariant that changing evidence withdraws a sign-off | the bytes (`EmployeeDocumentStore`), the countdown (`DocumentExpiryService`) |
| `EmployeeDocumentStore` | where a file lands and how it comes back: private disk, minted name, `SelfieSanitizer` for images, `%PDF-` sniff for PDFs, a `StreamedResponse` with a sanitised download name | any knowledge of status or of who may see the row |
| `DocumentExpiryService` | `valid` / `expiring_soon` / `expired`, computed from the date against **the type's own window** (falling back to `hrms.expiry.default_warning_days`) | the stored `status`, which the service layer writes |
| `OnboardingService` | `draft → pending_documents → hr_review → completed`, the checklist, `materialise()`, and completion | deciding what a requirement means — that is `onboarding_requirements` |

`DocumentExpiryService` being authoritative is the reason a client can be
trusted to *draw* and not to *decide*: every row ships with `expiry_state`
and `days_until_expiry` already computed, so a phone with a wrong clock
still shows the server's answer.

**The scheduler is idempotent by construction.**
`ScanDocumentExpiries` walks every non-archived document with an expiry date,
writes `expiry_notified_at` and raises `DocumentExpiring` / `DocumentExpired`
— two events raised now, wired to nothing, because FCM is still unstarted and
a notification hook should be a subscriber rather than a `->notify()` in a
loop. Re-running in the same window changes nothing and raises nothing: the
marker is *on the row*, so two overlapping workers cannot both win, and
`withoutOverlapping()` / `onOneServer()` remain documented requirements rather
than assumptions. Scheduled at `hrms.expiry.scan_hour:6`, `scan_minute:15`
in `routes/console.php`.

**Onboarding is computed, never stored as eight booleans.** The checklist is
folded over `onboarding_requirements` at read time: any non-archived,
unexpired `valid` document of the matching type satisfies a `document`
requirement; otherwise the newest non-archived row decides
`pending_verification` / `rejected` / `expired`. `data` reads the employee's
own columns; `bank` reads `employee_bank_accounts`. Completion is refused
with **409 naming each outstanding requirement** — a 403 would say "you may
not" when the truth is "not yet", and the two lead to different next actions.

**Bank details are a different kind of secret and are shaped like one.**
They live in a 1:1 side table with `encrypted` casts, are written through two
routes with **no `permission:` middleware** (`EmployeePolicy::viewBankAccount`
/ `updateBankAccount` decide per row), and are absent from `EmployeeResource`
by construction rather than by omission — the leak a `hidden()` forgets cannot
happen. The cost is that `APP_KEY` must not be rotated without a
re-encryption pass, which is called out in `docs/SECURITY.md` §4.

**Row scope is one function, and it fails closed.**
`Visibility::mayViewOthersDocuments()` = `documents.view` **and**
`documents.manage`; `employeeDocumentsFor()` and `employeeDocumentIsVisible()`
default to *your own rows*; `onboardingEmployeesFor()` / `onboardingIsVisible()`
= own or `onboarding.manage`. Every one of them is a single place to read and
a single place to test.

### 3.9 Phase 11 ✅ — Training & asset management

Two registers in one slice, because they have the same shape: a **thing that
exists** (`training_programs`, `assets`), a **vocabulary describing it**
(`training_types`, `asset_types`) and a **ledger of what happened to it**
(`employee_trainings`, `asset_assignments`). Nothing else about them is
alike, and the architecture is mostly about keeping the differences honest.

**Configuration, not rules — §2.2 taken literally.** The code contains no
list of training types and no list of asset types. `TrainingTypeSeeder` and
`AssetTypeSeeder` write eight and seven rows; `GET /training-types` and
`GET /asset-types` read them back as **plain arrays** (a vocabulary is not a
paginated resource); a program's `code`, a course's `duration_days` and an
asset's `purchase_cost` are columns a form fills in. A deployment that needs
`POWER_TOOLS` adds a row.

**Three writers, and no controller writes a column.** `TrainingService`,
`TrainingExpiryService` and `AssetService` are the only mutation paths.
That is what makes three otherwise-unrelated guarantees cheap:

- *the 409 ordering* — `AssetService::changeStatus()` consults the
  open-assignment rule **before** `Asset::TRANSITIONS`, so `assigned` is
  never a legal answer to "change the status" and a held asset cannot be
  retired out from under its holder. A controller that checked the table
  first would have had to remember this;
- *the uniqueness race* — `assign()` opens its row inside a transaction
  under `lockForUpdate()`, because "one open hand-over per asset" cannot be
  a `UNIQUE` index (many rows per asset over a life, only one open) and
  cannot be a pre-read either;
- *the audit log that is coming* — Phase 12 attaches to one method per act
  rather than to eighteen controllers.

**A service refuses a state; a form request refuses a payload.** The split
is the same one Phase 9 drew, and it is why a bad state is a **409 naming
the state** while a bad field is a **422 naming the field**. Two duplicate
enrolments, an asset already held, a status the transition table does not
allow and a certificate-required program with no certificate are all
refusals with a sentence a person can act on.

**Expiry is derived, and the derivation lives on the model.**
`EmployeeTraining::certificateExpiryState()` folds the certificate's own
dates against `hrms.expiry.default_warning_days` (30) into `none | valid |
expiring_soon | expired`, and both the API and the Flutter screen **render
that answer** rather than recomputing it — a server that says *expiring soon*
and a phone that says *valid* is two answers to one question, and only one
of them can be patched. The nightly `ScanTrainingExpiries` job
(`dailyAt`, `withoutOverlapping()`, `onOneServer()`, `ShouldBeUnique`,
`$tries = 1`) reads `employee_trainings` only, and writes nothing but
`expiry_notified_at` — the marker that makes a doubled schedule raise one
event per record per window instead of two. It raises
`EmployeeTrainingExpiring` / `EmployeeTrainingExpired` and **delivers
nothing**: the events are hooks for Phase 12.

**The register's status is a projection, and the history is the source.**
`assets.status` is not an opinion anybody types; it is what
`asset_assignments` implies — `assign()` opens a row and sets `assigned`,
`returnAsset()` closes it and sets `maintenance` (when the returned condition
was `poor`) or `available`. The return **writes into the row the hand-over
opened**, because that row *is* the hand-over; its `remarks` replace the
original, and a return filed with none leaves the original standing. That is
the difference between a history and a diary: you cannot quietly edit what
was said at hand-over time by saying nothing at return time.

**One voucher, one envelope.** A certificate is uploaded through
`EmployeeDocumentStore` — the same private store, the same six validation
rules, the same server-minted uuid name — and read back by exactly one route,
`GET /employee-training/{training}/file`, whose gate is deliberately *not*
`training.manage`: the right to correct an enrolment date is not the right
to read everybody's competence paper.

**Row scope is two more functions, and they fail closed.**
`Visibility::employeeTrainingsFor()` answers *your own rows* with
`training.view` and *the workforce's* only with `training.assign` /
`training.complete`; `Visibility::assetsFor()` narrows `assets.view` without
`assets.manage` to the assets you hold. `GET /training-compliance` tallies
**after** that filter, so a manager's compliance totals are never the whole
company's by accident — the kind of leak that a summary endpoint commits
silently because nobody wrote a `WHERE` for a screen that was only ever
going to be used by one role.

**No FCM, no audit logging, no type CRUD — deliberately.** Both expiry jobs
already emit events, every mutation already runs through a service, and both
vocabularies are readable and seedable. Each of the three is a *hook*, and
Phase 12 will fill it; none of them is a gap that needs a placeholder now.

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
Local storage (prefs + files)  ← offline queue
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

**Local storage** — offline. The original sketch called for Drift/SQLite, and
the reason still holds for anything *relational*: attendance against sites,
projects and employees, needing transactions and a unique constraint. What
Phase 5 actually built is narrower than that and deliberately so:

```
AttendanceOfflineEvent  ← one JSON blob under a single SharedPreferences key
selfie bytes            ← <docs>/attendance-offline-selfies/{uuid}.jpg
```

A queue of outbound events is a list, not a set of relations — each row is
already self-contained (the action, the site, the coordinates, the accuracy,
the device id, the `client_event_id`, the sync status, the last error), and
the duplicate-prevention unique index lives **on the server**, which is where
it has to live anyway. Adding a relational engine to hold a list of things
that are about to be deleted would have been a dependency bought for one
feature that does not need it. Drift stays the plan for the modules that
really are relational (timesheets, payroll runs) — and when those land, the
queue moves with them behind the same `OfflineQueueStore` interface, so
nothing above it changes.

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
│   ├── salary_certificates/
│   ├── training/
│   ├── assets/
│   └── notifications/
│
└── main.dart
```

**What Phases 3–4 actually created** — `core/router/` and `core/permissions/`
are additions to the list above (GoRouter and the permission scope live with
the rest of the cross-cutting plumbing), as is `core/presentation/` for the
widgets every list and form screen shares:

```
mobile/lib/
├── core/
│   ├── config/app_config.dart          # --dart-define base URL
│   ├── network/api_client.dart         # Dio, bearer interceptor, session-rejected stream
│   ├── network/api_exception.dart      # envelope → ApiException
│   ├── permissions/permission_scope.dart # one place that answers "may they?"
│   ├── presentation/
│   │   ├── paged_list_view.dart        # loading / empty / error+retry / rows
│   │   ├── list_state.dart             # PagedListController<T>, generation guard
│   │   ├── form_controls.dart          # StatusField, FormBanner, StatusFilter
│   │   ├── fields.dart                 # LabeledTextField, DateField
│   │   ├── remote_picker.dart          # debounced, searchable option sheet
│   │   ├── status_chip.dart            # ← Phase 6, StatusTone for every module
│   │   ├── no_permission.dart          # ← Phase 6, the gate a list draws
│   │   ├── money.dart                  # ← Phase 8, the only formatter that prints money
│   │   ├── pdf_opener.dart             # ← Phase 8, hand a generated PDF to the OS
│   │   └── camera_capture_sheet.dart   # ← Phase 6, shared selfie/certificate flow
│   ├── data/approval_step.dart         # ← Phase 6, one approval chain step
│   ├── data/device_camera.dart         # ← Phase 6, front/back lens abstraction
│   ├── router/app_router.dart          # GoRouter + refreshListenable guard
│   ├── storage/token_store.dart        # flutter_secure_storage
│   └── storage/device_identity.dart    # stable per-install device name
├── features/
│   ├── auth/                           # deliberately flat (5 files)
│   ├── home/home_screen.dart           # permission-gated module tiles
│   ├── employees/                      # ↓ three-layer, as below
│   ├── departments/
│   ├── designations/
│   ├── projects/
│   ├── sites/
│   ├── attendance/                     # ← Phase 5, three-layer (below)
│   ├── leave/                          # ← Phase 6, three-layer
│   ├── timesheet/                      # ← Phase 6, three-layer
│   ├── overtime/                       # ← Phase 6, three-layer
│   ├── holidays/                       # ← Phase 6, three-layer
│   ├── site_reports/                   # ← Phase 7, three-layer: activity form,
│   │                                   #    daily report, PDF download, GPS,
│   │                                   #    repeatable rows, local drafts
│   ├── payroll/                        # ← Phase 8, three-layer: ledger, run report,
│   │                                   #    summary, and the salary-slip list
│   ├── loans/                          # ← Phase 8, three-layer: schedule, progress,
│   │                                   #    approve / reject with remarks
│   ├── salary_certificates/            # ← Phase 8, three-layer: ask, decide, PDF
│   ├── expenses/                       # ← Phase 9, three-layer: claim list, form,
│   │                                   #    detail + approval timeline, receipt capture
│   ├── documents/                      # ← Phase 10, three-layer: document list,
│   │                                   #    expiry report, upload/edit form (camera,
│   │                                   #    gallery, PDF), detail with verify/reject
│   ├── onboarding/                     # ← Phase 10, three-layer: joiner directory,
│   │                                   #    checklist detail, stage + completion
│   ├── training/                       # ← Phase 11, three-layer: enrolment list,
│   │                                   #    detail + completion/cancel sheet, enrol
│   │                                   #    form, course catalogue + course form,
│   │                                   #    expiry report, compliance summary
│   └── assets/                         # ← Phase 11, three-layer: register list,
│                                       #    detail, add/edit form, hand-over log,
│                                       #    the three hand-over sheets
└── main.dart
```

Each of the four Phase 6 features follows the same three layers, and the
split is load-bearing rather than cosmetic:

```
features/leave/
├── domain/      # LeaveRequest, LeaveType, LeaveBalance + LeaveRepository
│                #   ← no Flutter import, no JSON, no HTTP
├── data/        # ApiLeaveRepository implements LeaveRepository
│                #   ← the only place that knows the envelope
└── presentation/# controller (Riverpod Notifier), list / detail / form screens
```

The screens never construct a model from JSON and never see an envelope:
they are handed a `LeaveRepository`. That is what lets the tests substitute
an in-memory double (see TESTING §5) and assert on behaviour — "a pending
request shows approve only to an approver" — instead of on parsing.

Five files do not justify four directories, so `auth/` stays flat. The Phase 4
modules are the first to have local sources of their own, and each takes the
same shape — `data/` (models as JSON, repositories, Dio implementations),
`domain/` (the typed model and its repository *contract*), `presentation/`
(screens, controllers):

```
features/employees/
├── data/
│   ├── api_employees_repository.dart   # the Dio implementation
│   └── employee_providers.dart         # repository + list/picker providers
├── domain/
│   ├── employee.dart                   # Employee, fromJson, summary
│   ├── employees_repository.dart       # the contract the fake implements
│   └── employee_site_assignment.dart   # (assignments, shared with sites)
├── presentation/
│   ├── employees_list_screen.dart
│   ├── employee_detail_screen.dart
│   ├── employee_form_screen.dart
│   └── employees_controller.dart       # PagedListController<Employee>
```

The split matters for one reason above the others: `domain/` holds a
*contract*, so a test can substitute a scripted repository that records what
the screen asked for without the screen knowing. **`attendance/` took this
shape in Phase 5 ✅**, and it is where the offline queue lives:

```
features/attendance/
├── data/
│   ├── api_attendance_repository.dart   # Dio: today, check-in, check-out, visits, movement
│   ├── offline_queue.dart               # OfflineEvent, OfflineQueue, OfflineQueueStore
│   ├── device_location.dart             # LocationGateway → geolocator
│   ├── selfie_camera.dart               # CameraGateway → front camera, permission states
│   ├── selfie_compressor.dart           # image package, isolate-safe
│   └── client_event_id.dart             # one UUID generator for idempotency
├── domain/
│   ├── attendance_repository.dart       # the contract the fakes implement
│   ├── attendance_record.dart           # AttendanceRecord
│   ├── assigned_site.dart               # AssignedSite (+ geofence inputs)
│   ├── today_status.dart                # TodayStatus, the day's whole payload
│   ├── movement_event.dart              # MovementEvent
│   ├── location_fix.dart                # lat/lng/accuracy, isUsable
│   └── local_geofence.dart              # advisory haversine verdict
├── presentation/
│   ├── attendance_screen.dart           # today, location, picker, actions, queue
│   ├── attendance_controller.dart       # AttendanceController, AttendanceState
│   └── selfie_capture_sheet.dart        # open → preview → shutter → review → retake
```

`site_visits/` from the sketch above is **not** a separate feature: a visit
is part of the same day at the same site, shares the same geofence, the same
queue and the same `client_event_id` rules, and is one screen away from a
check-in. Splitting it would have meant two copies of all of that.

**Why feature-first rather than layer-first?** Attendance and payroll change for
different reasons and at different speeds. Isolating them means a change to payroll
cannot break attendance.

---

## 5. Key Design Decisions

### 5.1 One attendance row per employee per work day ✅ (Phase 5)

`attendances` holds a single row per employee per date, carrying check-in *and*
check-out columns for the one `site_id` the day belongs to. Intra-day movement
is modelled separately in `site_visits`, and
`UNIQUE (employee_id, attendance_date)` makes the rule an index rather than a
habit — a second check-in is refused by the database before any application
logic gets a chance to be wrong.

Check-out must happen **at the site the day started at** (`422` on `site_id`
otherwise). Moving somewhere else for the afternoon is expressed as a site
visit, not as another attendance row, because "the day" is one continuous
thing and `working_minutes` has to describe all of it.

*Alternative considered:* one row per punch. Rejected — computing daily working hours
and detecting missing check-outs becomes significantly harder.

**Also decided in Phase 5:** `check_in_at`, `check_out_at`,
`scheduled_start_at`, `scheduled_end_at`, `site_visits.started_at` and
`site_visits.ended_at` are **`datetime`, not `timestamp`**. MariaDB 10.4 runs
with `explicit_defaults_for_timestamp=0`, which gives the first `NOT NULL
timestamp` column an implicit `ON UPDATE CURRENT_TIMESTAMP` — it silently
rewrote the check-in time the moment the row was checked out. The migration
docblocks record this, because the next person adding a time column will
otherwise add a `timestamp` and lose a day of data.

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

Seeded rules (Phase 2: 12 rows — grace period, overtime threshold, default
geofence radius, late-arrival escalation, sick-certificate deadline,
certificate-required threshold, notification reminder offset, daily digest
time, document expiry warning, default working hours (JSON), date format,
currency). Later phases added the leave, reporting and payroll rules — **18
rows today**, four of them `payroll.*`, the fourth being the net-salary floor
in §5.14.

**Currency is a pair of settings, not a constant.** `system.currency` is the
company's own code (`AED` for this build) and is what payroll, the PDF
documents, `PayrollResource`, `LoanResource` and the default on a new expense
claim all read; `system.supported_currencies` (`json`) is the list an expense
claim may be filed in — one entry means the form offers no choice, two or more
make it a menu, and an empty list switches the membership rule off rather than
refusing every claim. Both reach a phone through **`GET /client-settings`**,
an allow-listed, read-only, permission-free endpoint (see
[`API_DOCUMENTATION.md`](API_DOCUMENTATION.md) §2.10b), so the app *reads* the
currency instead of remembering it.

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

### 5.4 Offline sync uses client-generated idempotency keys ✅ (Phase 5)

Each queued action gets a UUID **created on the device before the first
attempt goes out**, so "the server accepted it but the reply was lost" and
"a genuine retry" become the same event from the server's point of view.
Four unique constraints hold it: `attendances.client_event_id`,
`attendances.check_out_client_event_id`, `site_visits.client_event_id` and
`site_visits.end_client_event_id`. A row already carrying that key returns
its original record and evaluates nothing else; two simultaneous replays
race to the index, and the loser re-reads the winner's row.

There is **no batch endpoint**. Each event replays through the route that
would have carried it live, so one code path owns the rules, and the server
still re-validates assignment, geofence and accuracy on every sync.

*Alternative considered:* a `POST /attendance/sync` accepting a list of
actions. Rejected — it needs its own validation path, its own partial-failure
semantics and its own tests, all to avoid N small requests that the
`attendance` limiter (30/min per user) already bounds.

### 5.5 Private storage with protected access ✅ (Phase 5, for selfies)

Selfies, passports, Emirates IDs, visas, contracts and salary documents live
outside `public/`.

**What is stored is never what was sent.** `SelfieSanitizer` decodes the
upload with PHP's bundled **GD**, flattens any alpha onto white, and
re-encodes it as JPEG (quality 85, `hrms.storage.selfie_jpeg_quality`);
only those bytes are written, to a server-minted
`{employeeId}/{uuid}.jpg`. Because EXIF lives in JPEG APP segments that a
pixel decoder discards, re-encoding *is* the metadata removal — no GPS fix,
no camera identification, no embedded thumbnail — and the original file and
filename are read once and never written. GD is already present, so no new
Composer dependency was added.

This is the **security boundary**: it runs for every request, including from
clients that bypass the Flutter app entirely. `SelfieCompressor` performs
the same re-encode on the device beforehand, which is a courtesy to a
patchy site signal rather than a control. If GD were missing the sanitiser
refuses to store anything rather than falling back to the raw upload.

For the check-in selfie the access path is **not** a signed URL but a
policy-gated route: `GET /api/v1/attendance/{id}/selfie` runs
`AttendancePolicy::viewSelfie` and streams the file off the `local` disk
(`storage/app/private`) with `Cache-Control: no-store`. A signed URL is a
bearer secret with a lifetime, and it hands the storage layout and the
employee id to anyone holding the link; a route answers "may *this* user
see *this* person's photograph" with the same code that answers every other
row question, and revocation is a permission change rather than a wait.

No response contains a filesystem path, a base64 image, or a link to
somebody else's selfie. Documents arriving in later phases follow the same
rule — sanitise on write, serve through a policy.

### 5.6 Status is decided in exactly one place (Phase 5)

`present`, `late`, `incomplete`, `missing_checkout` and `manually_adjusted`
are written by `AttendanceStatusCalculator` and by nothing else — not by a
controller, not by a resource, not by a query. The rules are ordered rather
than boolean: a day that was both late *and* short of its hours is
`incomplete`, because the thing an employee needs to fix is the missing
hours, and a status that only ever says "you were late" would hide it.
`manually_adjusted` is reserved with no writer yet, so the override/audit
work of a later phase changes rows without migrating them.

The same rule applies to time: `WorkingTimeCalculator` is the only place
that subtracts a scheduled break, floors a duration, or measures an
overnight window across midnight. No controller does arithmetic.

### 5.7 The phone advises; the server decides (Phase 5)

`LocalGeofence.assess()` on the device and `App\Support\Geo` on the server
are the same haversine written twice, on purpose: the phone needs to tell a
person standing in the wrong field that they are about to be refused,
before they take a selfie for nothing. What the phone says is shown with
the word *advisory* and the note that the server measures again — because
the request carries coordinates, not a verdict, and a modified client that
pre-approves itself changes nothing.

`hrms.attendance.max_gps_accuracy_metres` is returned by
`GET /attendance/today` so the advisory check uses the server's number
rather than a copy that would drift the moment the config changed.

### 5.8 A site visit is two points, never a track (Phase 5)

`site_visits` stores a start and an end, each with its own coordinates,
accuracy and computed distance, and nothing in between. There is no
background location permission, no polling, no upload-when-moving: the app
requests foreground location only, and every coordinate it ever sends was
captured at the moment a person pressed a button. A visit that starts
inside the boundary cannot be ended from outside it, because each end
re-runs the geofence against its own reading.

### 5.9 A timesheet is a derived snapshot, never a record someone signs off (Phase 6)

`timesheets` has no `approved_by`, no approval column and no create endpoint,
because there is nothing to create and nothing to approve: a row is a
projection of one attendance day, generated by
`TimesheetService::generate(from, to)` from the working-time maths. Running
it twice upserts (the unique key is `(employee_id, timesheet_date)`), so the
button is safe to press again after a correction.

`status` on a timesheet is `open` / `complete` / `incomplete` — a property of
*the day*, derived from how the hours landed, not a sign-off. Confusing the
two would invite an approval workflow around a number that can be recomputed,
and the recomputation would then be the thing that disagreed with the
approved record.

The `attendance_id` is `nullOnDelete`: if a source day is ever removed the
snapshot stays as history rather than vanishing, and `attendance_id` being
nullable is what lets a timesheet exist for a day attendance has not yet
produced.

### 5.10 The approval chain is frozen at submit (Phase 6)

Editing an approval workflow is a configuration change; it must not reach
backwards. So `ApprovalWorkflowService::submit()` resolves every step once,
writes one `approval_records` row per step with the *resolved* approver, and
never consults the definition again for that subject.

Three things fall out of that:

- **A request already in flight keeps its chain.** Renaming a role or
  retiring a workflow cannot silently change who is waiting on what.
- **Unresolvable steps are skipped, not fatal.** If step 2 names a
  reporting manager the employee does not have, the step is recorded with a
  remark and passed over — never deleted, so the gap is visible in the
  record rather than papered over.
- **History is a query, not a reconstruction.** `approval_records` is the
  timeline; there is no need to replay definition changes to explain a past
  decision.

There are deliberately two vocabularies:
`approval_workflows.subject_type` is the *subject* (`leave`, `overtime`) and
`approval_records.subject_type` is the *record* (`leave_request`,
`overtime_request`). Conflating them would make "which definition" and
"which row" answerable by the same string and therefore neither.

**Phase 9 ✅ — a third of each, and nothing else changed.**
`ApprovalWorkflow::SUBJECT_EXPENSE = 'expense'` adds the third subject (the
seeded `EXP-STD` is its default definition), and
`ApprovalRecord::TYPE_EXPENSE = 'expense'` adds the third record, with an
`expense()` accessor beside `leaveRequest()` and `overtimeRequest()`. The
record keeps the bare noun while the other two are singularised table names
because the two vocabularies are two questions, not one — "which definition"
and "which row" must not collapse into each other, whichever shape either
takes. `ApprovalWorkflowService` widened its parameter union to
`LeaveRequest|OvertimeRequest|Expense` and grew only two `match` arms
(`subjectType()`, `workflowSubjectType()`); every other line of the engine —
freeze at submit, skip unresolvable steps, close behind a refusal, refuse
self-approval — is the Phase 6 code running unchanged for a third subject.
`ExpenseService` is the caller that decides *when* the chain starts and
settles; who approves it is still data in `approval_workflows`, never a fact
about expenses.

---

### 5.11 A report's author and its project are derived, never asserted (Phase 7)

Neither report family accepts an author. `employee_id` and `created_by` are
read from the bearer token in the service, and `project_id` is not trusted
either: both stores require it *alongside* `site_id`, the request refuses
any pair whose project does not own the site, and the service writes
`$site->project_id` anyway. Three layers for one idea — a report is about a
place somebody is standing, and the place decides the project.

The same reasoning gives status its own verb. There is no `status` key in
any create or update body; `POST …/submit` is the only transition, and an
edit to a filed report answers `409` rather than quietly reopening it.
Making status a writable field would mean the author could un-submit their
own work by sending a form with the word `draft` in it.

Location follows the pattern. GPS is optional on a draft and required at
submit — but taken *from the submit request*, never merged from the stored
row. A reading taken five minutes earlier describes a different moment from
the one at which the report left the phone, and the field is named for the
moment of submission.

### 5.12 The PDF is rendered on demand and never stored (Phase 7)

`GET /daily-site-reports/{report}/pdf` builds the document at request time
with `barryvdh/laravel-dompdf` and streams it, with
`Cache-Control: no-store` on the response. Nothing is written to disk, no
`pdf_path` column exists, and no queue job produces one.

The alternative — storing a PDF at submit — was rejected for reasons that
all reduce to the same one: **a stored document is a second source of
truth.** It freezes the report at the moment of filing (a correction would
leave the old PDF answering questions the row no longer supports), it needs
a lifecycle (when is it deleted? who may see it after a re-submit?) and it
turns a private file into a URL that must then be protected forever. On
demand has no second copy to expire, no path to leak, and always renders
what the row says now.

The cost is that building one takes a moment on each press, which the app
answers with an explicit "Preparing…" state on a disabled button. `html()`
and `response()` are separate methods precisely so a test can assert on the
document's *content* without parsing PDF bytes.

Photographs are embedded as `data:` URIs rather than referenced, capped at
six, so the file is genuinely self-contained when it reaches a reader — and
so generating it never has to write an image anywhere.

---

### 5.13 Money is an integer of paise, never a double (Phase 8)

A payroll figure that is wrong by a cent is wrong in a way a person will
notice on their payslip, so Phase 8 refuses the type that produces that kind
of wrongness at all:

| | |
|---|---|
| Columns | `DECIMAL(12,2)` — never `FLOAT`, `DOUBLE`, or `REAL`, anywhere in the schema |
| Wire | decimal **strings** (`"30000.00"`), so a JSON number cannot lose digits in transit |
| Rounding | one helper, `App\Support\Money`, scale 2, PHP `round()` — half away from zero, no bcmath extension required |
| Printing | one formatter, which emits `INR 30,000.00`; the currency code comes from the row, not a constant |
| Client | `Money.format()` in `core/presentation/money.dart`, which parses the string into **minor units** and never holds a `double` |

The client side is the part that usually goes wrong. Turning `"30000.00"`
into a Dart `double` and printing it with `toStringAsFixed` would re-round a
figure the server had already settled, and two screens would then disagree
about one payslip by a cent. So the app parses to an integer of paise,
formats *that*, and rounds the same way PHP does — `0.125 → 0.13` on both
sides, which is what lets a test assert a literal like `INR 28,500.00`.

Nothing in this module formats a figure by hand: `MoneyText` is the widget
every list and detail screen uses, and a screen that writes `"₹ 30,000"`
inline will be wrong the first time the organisation changes
`system.currency`.

**What is deliberately *not* enforced:** a negative `net_salary` produced by
**attendance or an approved adjustment** is passed through as it stands.
When unpaid days exceed a month's pay, clamping to zero would make the slip
lie about work that was not done — the number is the truth, and the sentence
around it is what needs writing, not the figure. The one deduction the
company *can* postpone is held to the floor instead; see §5.14.

---

### 5.14 A repayment may not pay a salary below the floor (Phase 8 hardening)

`gross − deductions = net` is the identity a payslip may never break, and
the original answer to "what if deductions exceed earnings?" was to report
the negative rather than clamp it. That answer remains right for loss of pay
and approved adjustments, and it is wrong for the one deduction that is owed
to the company, is priced by the company, and can simply wait a month.

| | |
|---|---|
| Rule | `room = gross − (LOP + approved adjustments) − payroll.minimum_net_salary` — a `settings` row, **`0`** by default ("never pay a negative salary"), never a constant in the calculation |
| Each installment takes | `min(amount − deducted_amount, room)`, offered **oldest due date first** |
| The answer travels as | `App\Services\Payroll\RepaymentAllocation` — scheduled, taken before, taken now, left — handed to both writers so the payslip line and the loan balance cannot disagree |
| What does not fit | stays on the installment: `partially_deducted` when part was taken, still `pending` when nothing was. Either way it is offered to the next run, together with any older installment whose due date has passed — a payment the floor blocked is *delayed*, never skipped |
| The balance moves by | what was **taken**, so `loans.outstanding_balance` never claims a repayment that was not collected |
| Never rewritten | LOP, unpaid leave and approved adjustments — capping those would falsify the payslip rather than protect it. If they alone put the row under the floor, the figure is reported honestly |
| Locked rows | `reviewed` / `processed` / `locked` refuse recalculation (409) and are counted `skipped` by a second run, so retuning the floor afterwards cannot re-cut a deduction somebody has already been paid from |

**Why a column, not a second table.** One installment can be shared by two
runs — September took 400.00 of a 1,000.00 payment and October takes the
rest — and `loan_installments.payroll_id` is one column that can only name
the most recent claimant. The per-run half of that fact already exists, on
the month's own `payroll_items` lines (`source_type = loan_installment`,
carrying `scheduled_amount` / `deducted_amount` / `remaining_amount` in
`metadata`), so `LoanService::releaseInstallments()` reads *those* to give
back exactly one month's share and leave another's standing. A
`loan_installment_claims` table would add a join to every read of the
schedule in order to answer a question one existing column already answers.

Both mutators stay inside `LoanService`, behind `lockForUpdate()`, and clamp
the amount to what is still outstanding before writing — which is what stops
two concurrent runs taking the same remainder, and what lets the same
installment be released and re-claimed by a recalculation exactly once.

**Deliberately not decided here:** whether a statutory minimum applies (the
UAE's Wage Protection System, for instance) is a jurisdictional question,
not an engineering one, so no statutory floor is assumed and the setting is
documented as requiring validation before production. There are likewise no
UAE statutory deduction rules in this pass, and no full audit logging yet —
every payroll and loan mutation already runs through a service, which is
where audit logging will attach.

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
  • Queue worker      (LOP conversion today; exports later — the report
                       PDF is rendered on demand, §5.12, and needs no job)
  • Laravel Scheduler (LOP conversion now; document expiry, reminders later)
  • FCM               (push delivery)
  • Backup job
  • Monitoring / logging
```

**Both side processes are mandatory, not optional** — the scheduler only
*dispatches* `EnforceSickCertificateDeadlines`, the worker is what runs it.
One without the other means overdue sick leave stays `pending` forever while
everything else looks healthy. See docs/DEPLOYMENT.md §4–§5 for the systemd
and Supervisor units and the cron entry.

What each layer owns:

| Value | Where it lives | Why |
|---|---|---|
| Rate limits, geofence bounds, GPS ceiling, upload sizes, scheduler tick | `config/hrms.php`, `config/rate_limiting.php` (env-backed) | deployment mechanics — tuned per environment without a code change |
| Role visibility narrowing | `config/hrms.php` `visibility` | code, not env: policies *and* list queries read the same arrays, so a typo in `.env` could not silently widen them |
| Certificate deadline, grace period, working hours | `settings` table | business rules — changed by whoever runs the company, not by whoever deploys |

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
| Phase 2 | RBAC implemented (spatie ^6.25, 10 roles / 40 permissions, middleware aliases); `settings` table + `SettingsService`; append-only assignments; core schema (14 migrations, 23 tables) |
| Phase 3 | Authentication — login / logout / sessions / change-password / forgot-password (501 until a mailer), named rate limiters, the success-and-failure envelope in `ApiResponse`, Flutter auth + GoRouter guard |
| Phase 4 | First vertical slice — departments, designations, employees, projects, sites and employee-site-assignments (Form Requests, Resources, services, transactions, six policies, `employees.salary.view`); Flutter `data/domain/presentation` features, permission-gated home, 13 screens |
| Phase 5 | GPS attendance and site movement — `attendances` + `site_visits` (2 migrations), `GeofenceService`, `WorkingTimeCalculator`, `AttendanceStatusCalculator`, `AttendanceService`, 11 routes, `Attendance`/`SiteVisit` policies, private selfie storage, `attendance` rate limiter, movement timeline; Flutter `features/attendance/` with location + camera permission flows, advisory geofence, offline queue with `client_event_id`, 176 tests |
| Phase 6 | Leave, timesheets and overtime — 9 migrations (34 tables total), configurable leave types, transactional balances, `LeaveDayCalculator`, holiday calendar, materialised approval workflow engine, sick-certificate upload + hourly deadline job with LOP conversion, derived timesheets, overtime with `payroll_eligible`; 7 policies (with the `<Model>Policy>` naming rule), 11 permissions (51 total / 236 grants), 37 routes; Flutter `features/{leave,timesheet,overtime,holidays}` + shared `StatusChip` / `CameraCaptureSheet` / `NoPermission`, 215 tests |
| Post-Phase 5 hardening | Server-side selfie sanitisation — `SelfieSanitizer` (GD decode → flatten → JPEG re-encode, EXIF/GPS stripped, original never stored) + `App\Rules\ImageContent` (header decode check + pixel budget); closes §4.3's "EXIF is stripped by the app, not by the server" gap; no new dependency; `AttendanceSelfieSanitizationTest` (13), backend 321 tests |
| Phase 7 | Site activity reports and daily site reports — 7 migrations (**41 tables total**), `SiteActivityReportService` / `DailySiteReportService` (author and project derived, `draft → submitted` only, one official report per site-day), `StoresPrivateImages`/`ReportPhotoStore` on the fail-closed `SelfieSanitizer`, `DailySiteReportPdf` on demand with dompdf (**§5.12**), 2 policies + `Visibility` row scoping, 8 permissions (**59 total / 278 grants**), 18 routes (104 definitions / 109 registered); Flutter `features/site_reports/` with GPS, repeatable rows, camera photos and local drafts, **303 tests** |
| Phase 8 | **Payroll, loans and salary documents** — 7 migrations (**48 tables total**), `App\Support\Money` (**§5.13**), `PayrollCalculationService` outside any controller (attendance → overtime → LOP → allowances → adjustments → loans, all reusing the Phase 5/6 calculators), a one-way `draft → calculated → reviewed → processed → locked` ladder with a `payroll_id` on every installment it takes, on-demand salary-slip and certificate PDFs with no stored file, 5 policies (22 total), 11 permissions (**70 total / 332 grants**), 3 `payroll.*` settings (16 total), 36 routes (**140 definitions / 145 registered**); Flutter `features/{payroll,loans,salary_certificates}` + `core/presentation/{money,pdf_opener}.dart`, **377 tests** |
| Post-Phase 8 payroll hardening | **Financial safety** — negative `net_salary` can no longer come from a repayment (**§5.14**): `loan_installments.deducted_amount` (1 migration, **48 tables / 41 migrations**) + `partially_deducted`, `RepaymentAllocation` carrying scheduled / before / now / left, `payroll.minimum_net_salary` setting (4 `payroll.*`, **17 total**), carry-forward read `due_date ≤ period end ∧ status ∈ {pending, partially_deducted}` with per-run provenance on `payroll_items`, `LoanInstallmentResource` wired into `LoanResource` (`remaining_amount` on the schedule and `next_installment`); no UAE statutory rule and no audit logging added — both deferred, services ready for it; `PayrollRepaymentTest` (11), backend **469 tests / 2915 assertions**, Flutter **378 tests** |
| Phase 9 | **Expense management** — 3 migrations (**51 tables / 44 migrations**: `expense_categories`, `expenses`, `expense_receipts`), `ExpenseService` (every mutation in a transaction; field errors as `ValidationException`, illegal state as `409` naming the state) + `ExpenseReceiptStore` (private `expense-receipts/{expenseId}/{uuid}.{ext}`, config `hrms.storage.expense_receipt_{directory,max_kilobytes}`), a thin `ExpenseController`, 4 FormRequests (`StoreExpense`, `UpdateExpense`, `ActOnExpense`, `StoreExpenseReceipts`), `ExpensePolicy` (11 abilities, including `viewReceipt`), `Visibility::{expensesFor, expenseIsVisible, mayViewOthersExpenses, mayClaimExpenseAt}` + a private `directReportIds()`, and the Phase 6 approval engine reused for a third subject (`ApprovalWorkflow::SUBJECT_EXPENSE`, `ApprovalRecord::TYPE_EXPENSE`, union widened to `LeaveRequest\|OvertimeRequest\|Expense`, seeded `EXP-STD`) — 3 permissions (**73 total / 364 grants**), 6 seeded categories, 4 workflows / 9 steps (17 settings unchanged), 13 routes (**153 definitions / 158 registered**), Flutter `features/expenses/` (list, form, detail, receipt capture); backend **499 tests / 3251 assertions** (Phase 9: `ExpenseTest` 20 + `ExpenseReceiptTest` 10) |
| Post-Phase 9 expense configuration | **Configurable currency** — `system.currency` reseeded `INR → AED` (read by payroll, both PDFs, `PayrollResource`, `LoanResource` and the new-claim default) plus `system.supported_currencies` (`json`, `["AED"]`, **18 settings total**); **`GET /client-settings`** (`ClientSettingsController`, sanctum only, no `permission:`, explicit allow-list of `default_currency` + `supported_currencies`, reconciled so the default it offers is always accepted); `StoreExpenseRequest` upper-cases `currency` in `prepareForValidation()` then validates it against the configured list (`allowedCurrencies()`), `UpdateExpenseRequest` unions that with the code the claim was already filed in so narrowing the setting cannot trap a draft; Flutter `core/config/client_settings.dart` (`ClientSettings` · `ClientSettingsSource` · `clientSettingsProvider`) pre-fills a new claim, shows one code read-only and offers several only when several are configured, opens the field on a fetch failure, and never fetches anything for an edit — a filed claim keeps its currency, and nothing converts; **1 route (154 definitions / 159 registered)**; backend **509 tests / 3288 assertions** (`ClientSettingsTest` 8 + `ExpenseTest` +2), Flutter **465 tests** (+11) |
| Phase 10 | **Employee documents & onboarding** — 5 migrations (**56 tables / 49 migrations**: `document_types`, `employee_documents`, `onboarding_requirements`, `employee_onboarding`, `employee_bank_accounts`), configuration instead of rules (`document_types.requires_*` + `expiry_warning_days`, `onboarding_requirements.kind = document\|data\|bank` matched **by code**), `EmployeeDocumentService` / `DocumentExpiryService` (authoritative `valid\|expiring_soon\|expired` per type) / `OnboardingService` (`draft → pending_documents → hr_review → completed`, materialised on first read, **409 naming what is outstanding** on completion) + `EmployeeDocumentStore` (private `employee-documents/{employeeId}/{uuid}.{ext}`, config `hrms.storage.document_{directory,max_kilobytes}`, images re-encoded through `SelfieSanitizer`, `%PDF-` sniff, `StreamedResponse` with a `[A-Za-z0-9 _-]` download name), `ScanDocumentExpiries` scheduled at `hrms.expiry.scan_{hour,minute}` and idempotent through `expiry_notified_at` with `DocumentExpiring`/`DocumentExpired` raised and **no FCM**, 5 FormRequests, 5 Resources, `Visibility::{mayViewOthersDocuments, employeeDocumentsFor, employeeDocumentIsVisible, onboardingEmployeesFor, onboardingIsVisible, mayFileDocumentsFor}` failing closed to own rows — **3 new policies (26 total)**: `EmployeeDocumentPolicy`, `DocumentTypePolicy`, `EmployeeOnboardingPolicy`, plus `EmployeePolicy::{viewBankAccount, updateBankAccount}` on two routes with **no `permission:` middleware** and `employee_bank_accounts` on `encrypted` casts outside `EmployeeResource` — 7 permissions (**80 total / 401 grants**, `documents.manage` + `onboarding.manage` held by exactly three roles), 2 seeders (9 document types, 8 requirements), 18 settings unchanged, 16 routes (**170 definitions / 175 registered**), Flutter `features/{documents,onboarding}/` + `DocumentFilePicker` on **`file_picker`** (the only package added), 7 `GoRoute`s (**65 total**), two Home doors, `PermissionScope` +10 getters, `ApiClient.putMultipart()`; backend **553 tests / 3674 assertions** (Phase 10: `EmployeeDocumentTest` 20 + `OnboardingTest` 12 + `DocumentExpiryScanTest` 6 + `EmployeeBankAccountTest` 6), Flutter **551 tests** (+86) |

| Phase 11 | **Training & asset management** — 6 migrations (**62 tables / 55 migrations**: `training_types`, `training_programs`, `employee_trainings`, `asset_types`, `assets`, `asset_assignments`), configuration instead of rules (`training_types` / `asset_types` seeded 8 + 7 codes, **no seeded programs**), `TrainingService` / `TrainingExpiryService` / `AssetService` (every mutation through a service, **409 naming the state** before `422` naming the field, `lockForUpdate()` around "one open hand-over per asset", `Asset::TRANSITIONS` consulted *after* the open-assignment rule) — an **enrolment ledger** with `UNIQUE (employee_id, training_program_id, enrollment_date)` so recertification is a new row, and a certificate uploaded through the **existing** `EmployeeDocumentStore` behind `GET /employee-training/{training}/file` (**§3.9**), certificate expiry derived per row by `EmployeeTraining::certificateExpiryState()` as `none|valid|expiring_soon|expired` against `hrms.expiry.default_warning_days`, `ScanTrainingExpiries` scheduled at `hrms.expiry.training_scan_{hour,minute}` idempotent through `expiry_notified_at` with `EmployeeTrainingExpiring`/`EmployeeTrainingExpired` raised and **no FCM**, 11 FormRequests, 6 Resources, `Visibility::{employeeTrainingsFor, assetsFor, assetAssignmentIsVisible, maySeeCertificateFor}` failing closed to own rows — **4 new policies (30 total)**: `EmployeeTrainingPolicy`, `TrainingProgramPolicy`, `AssetPolicy`, `AssetAssignmentPolicy`, with `TrainingType` / `AssetType` gated on the route only — 15 permissions (**95 total / 462 grants**: eight `training.*`, seven `assets.*`), 2 seeders, 18 settings unchanged, 24 routes (**194 definitions / 199 registered**), Flutter `features/{training,assets}/` (26 files), 13 `GoRoute`s (**78 total**), two Home doors, `PermissionScope` +16 getters, `ApiClient.patch()`; backend **609 tests / 4103 assertions** (Phase 11: `TrainingManagementTest`, `TrainingComplianceTest`, `TrainingExpiryScanTest`, `AssetManagementTest`, `AssetAssignmentHistoryTest` on the shared `BuildsTrainingAssets` scaffold), Flutter **654 tests** (+103) |