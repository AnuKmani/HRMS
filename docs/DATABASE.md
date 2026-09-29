# Database Design

> **Status:** Phase 9 — core schema, GPS attendance and site movement, the two
> report families, the payroll vertical slice and now the expense vertical
> slice. Laravel's base tables plus 10 Phase 2 migrations, the Phase 3
> `users.status` column, the two Phase 5 migrations, the nine Phase 6
> migrations, the seven Phase 7 migrations, the seven Phase 8 migrations, the
> repayment-floor migration that adds `loan_installments.deducted_amount`
> and the three Phase 9 expense migrations all exist: `settings`,
> `departments`, `designations`, `shifts`, `projects`, `employees`, `sites`,
> `employee_site_assignments`, `attendances`, `site_visits`, the
> leave/timesheet/overtime/holiday tables, **the seven site-report tables
> (§2.9)**, **the seven payroll tables (§2.7)** and **the three expense
> tables (§2.10)**, plus the `spatie/laravel-permission` RBAC tables — **51
> tables across 44 migrations** (Phase 8 closed at 48 tables across 41
> migrations; Phase 9 adds three of each). Tables for later phases
> (`employee_documents`, `trainings`, …) are **design only** and have not
> been created. `hrms_testing` mirrors this schema for the test suite.

**DBMS:** MariaDB 10.4.28 (XAMPP)
**Charset:** `utf8mb4` / collation `utf8mb4_unicode_ci`

### Current connection settings

| Setting | Value |
|---|---|
| Host | `127.0.0.1` |
| Port | `3306` |
| Database | `hrms_laravel` |
| Username | `hrms_app` — scoped to `hrms_laravel` + `hrms_testing` only, never `root` |
| Password | `DB_PASSWORD` in `backend/.env` (git-ignored, never committed) |

### Development vs testing database

Two separate databases. **Tests must never touch the development one.**

| Purpose | Database | Set by | Used for |
|---|---|---|---|
| **Development** | `hrms_laravel` | `backend/.env` → `DB_DATABASE` | `artisan serve`, tinker, migrations, local data |
| **Testing** | `hrms_testing` | `backend/phpunit.xml` → `<env name="DB_DATABASE">` | `php artisan test` |

**Why the separation is mandatory:** `RefreshDatabase` issues `migrate:fresh`, which
**drops every table** in the connected database. Before this was split, running the test
suite silently wiped local development data and required a re-seed afterwards.

```bash
php artisan test          # touches hrms_testing only
php artisan migrate       # touches hrms_laravel only
```

Rules that keep this safe:

- `phpunit.xml` overrides **only** `DB_DATABASE`. `DB_HOST`, `DB_PORT`, `DB_USERNAME`
  and `DB_PASSWORD` are inherited from `.env`, so **no credentials are committed**.
- `.env` always points at `hrms_laravel`. Never point it at `hrms_testing`.
- `hrms_app` holds **schema-level privileges on exactly these two databases** and
  `USAGE` globally — no `GRANT ... ON *.*`.
- Databases `hrms`, `hrms_corruption_probe` and every other schema on this server
  belong to unrelated projects and are **never** granted to `hrms_app`, never
  migrated, never dropped.

`hrms_testing` is disposable: it is recreated from the migrations on every test run
and holds no data worth keeping.

---

## 1. Conventions

| Rule | Detail |
|---|---|
| Primary keys | `id` BIGINT UNSIGNED AUTO_INCREMENT (UUID only where client-generated) |
| Foreign keys | Declared on every relationship, with `ON DELETE` chosen deliberately |
| Timestamps | `created_at`, `updated_at` on every table |
| Soft deletes | `deleted_at` on master data (employees, projects, sites) where recoverability matters |
| Money | `DECIMAL(12,2)` — never `FLOAT` |
| Dates | `DATE`; datetimes `DATETIME`; only server-generated times use `TIMESTAMP` |
| Booleans | `TINYINT(1)` |
| Enums | Lookup tables rather than MySQL `ENUM` where the list is user-configurable |
| Indexes | Every foreign key indexed; composite indexes for common filter combinations |
| JSON | `JSON` only for genuinely unstructured data (snapshot of an approval route, device info) |

**Why lookup tables over `ENUM`?** Leave types, expense categories, employment statuses and
document types are *configurable by HR*. An `ENUM` requires a schema change to add a value.

---

## 2. Entity Groups

### 2.1 System & Access

| Table | Purpose |
|---|---|
| `users` | Login accounts (1:1 with `employees`, except Super Admin) |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | RBAC — provided by `spatie/laravel-permission` |
| `settings` | Typed key/value config: working hours, grace period, OT threshold, default geofence radius, sick-cert deadline, document reminder lead time |
| `audit_logs` | Who changed what, old → new values, IP, timestamp |
| `personal_access_tokens` | Sanctum tokens (one per device) |
| `notifications` | Laravel database notifications (persistent inbox) |
| `notification_preferences` | Per-user, per-channel opt-in/out |

> `cache`, `sessions`, `jobs`, `job_batches`, `failed_jobs` come from the framework
> if the `database` driver is selected.

---

### 2.2 Organization

| Table | Purpose |
|---|---|
| `departments` | Org units |
| `designations` | Job titles |
| `employees` | The central master record |
| `employee_documents` | Passport, visa, Emirates ID, contract, certificates + expiry dates |
| `employee_onboarding` | Onboarding status and missing-document tracking |
| `trainings` | Training programmes |
| `employee_trainings` | Enrollment, completion, certificate + certificate expiry |
| `assets` | Company asset catalogue (laptop, phone, tools, safety equipment) |
| `asset_assignments` | Asset → employee, with dates, condition, status (history preserved) |

#### Key relationship

```
departments 1 ──── * employees * ──── 1 designations
                              │
                              ├── 1 ──── 1 users (nullable)
                              │
                              ├── * ──── 1 reporting_manager (self FK)
                              │
                              └── └── * ──── 1 photo (private storage path)
```

**Implemented ✅** — `departments`, `designations` and `employees` exist. Names are
stored **structured** (`first_name`, `middle_name`, `last_name`) rather than as a
single `full_name`, so records sort correctly by surname and print correctly on
contracts; `Employee::$full_name` and `$initials` accessors compose them on demand.

Sensitive documents are deliberately **not** columns on `employees` — `photo_path`
is a private-storage path, and everything else belongs to `employee_documents` (Phase 10).

---

### 2.3 Projects, Sites & Assignment

| Table | Purpose |
|---|---|
| `projects` | Client projects (Planned / Active / On Hold / Completed / Cancelled) |
| `sites` | Physical sites belonging to a project — holds `latitude`, `longitude`, `geofence_radius`, shift, working hours |
| `employee_site_assignments` | **Append-only** assignment history: employee, project, site, type (primary/temporary), start, end, status, created_by |
| `shifts` | Start, end, break duration, grace period, minimum working hours, overtime threshold |
| `holidays` | Public / company / **site-specific** holidays |

#### Why assignments are a separate table

The requirement is explicit: *do not simply overwrite the employee's previous site.*

**Implemented ✅** — migration `2026_09_27_000008`, model
`App\Models\EmployeeSiteAssignment`. Three integrity guarantees are enforced by the
schema and verified by `AssignmentHistoryTest`:

1. `RESTRICT` on all three FKs — you cannot hard-delete an employee, project or site
   that has assignment history.
2. No `deleted_at` — assignments are immutable history, not master data.
3. `end()` closes a row (`status = 'ended'`, `end_date = today`) instead of deleting it.

```
employee_site_assignments
┌────┬─────┬─────┬───────────┬────────────┬──────────┬────────────┐
│ id │ emp │ proj│ site      │ start_date │ end_date │ type       │
├────┼─────┼─────┼───────────┼────────────┼──────────┼────────────┤
│ 1  │ 7   │ 12  │ Site A    │ 2026-01-05 │ 2026-03-01 │ primary  │
│ 2  │ 7   │ 12  │ Site B    │ 2026-03-01 │ (open)    │ temporary  │
│ 3  │ 7   │ 14  │ Site C    │ 2026-06-10 │ (open)    │ temporary  │
└────┴─────┴─────┴───────────┴────────────┴──────────┴────────────┘
```

*Current site* = the row with `end_date IS NULL AND status = 'active'` and the latest
`start_date`. History is never destroyed.

#### Site geofence fields

```
sites
  latitude          DECIMAL(10,7)   -- never hard-coded
  longitude         DECIMAL(10,7)
  geofence_radius   DECIMAL(8,2)    -- configurable per site by HR/Admin
```

`DECIMAL`, not `FLOAT`: binary floating point rounds unpredictably, which matters
when a decision ("was this check-in inside the geofence?") hinges on centimetres.

A site may also point at a `working_hours_setting_id` row in `settings` rather
than copying hours into its own columns — configuration is referenced, not duplicated.

**Implemented ✅** — migration `2026_09_27_000007_create_sites_table`, model
`App\Models\Site`, tested by `ProjectSiteRelationshipTest`.

---

### 2.4 Attendance & Movement

| Table | Purpose | Status |
|---|---|---|
| `attendances` | **One row per employee per work day** — check-in/out, GPS, accuracy, distance, selfie, shift, scheduled window, minutes, status, source, idempotency keys | ✅ Phase 5 |
| `site_visits` | A bounded two-point episode at another site: start/end time, start/end GPS, purpose, remarks, status | ✅ Phase 5 |
| *site activity reports & daily site reports* | *moved to §2.9 — built in Phase 7* | ✅ Phase 7 |

#### `attendances` shape — as built

```
attendances
  id
  employee_id             FK → employees        RESTRICT
  project_id              FK → projects         RESTRICT
  site_id                 FK → sites            RESTRICT   ← one site per day
  attendance_date         DATE
  check_in_at             DATETIME, NOT NULL
  check_in_latitude       DECIMAL(10,7)
  check_in_longitude      DECIMAL(10,7)
  check_in_accuracy       DECIMAL(8,2)   metres, as reported by the device
  check_in_distance       DECIMAL(10,2)  metres from site centre — SERVER computed
  check_in_selfie_path    VARCHAR(500), private disk, never a URL
  check_out_at            DATETIME, nullable
  check_out_latitude      DECIMAL(10,7), nullable
  check_out_longitude     DECIMAL(10,7), nullable
  check_out_accuracy      DECIMAL(8,2),  nullable
  check_out_distance      DECIMAL(10,2), nullable
  shift_id                FK → shifts, nullable
  scheduled_start_at      DATETIME, nullable   ← resolved at check-in
  scheduled_end_at        DATETIME, nullable
  working_minutes         INT  elapsed minus scheduled break, floored at 0
  break_minutes           INT  the configured scheduled break
  overtime_minutes        INT  working − minimum − threshold, floored at 0
  late_minutes            INT  raw arrival gap; grace only decides isLate
  early_departure_minutes INT  before scheduled end, floored at 0
  status                  VARCHAR(30)  present | late | incomplete |
                                        missing_checkout | manually_adjusted
  source                  VARCHAR(20)  online | offline | manual
  device_reference        VARCHAR(100), nullable
  notes                   VARCHAR(500), nullable
  client_event_id         UUID, UNIQUE   ← check-in idempotency
  check_out_client_event_id UUID, UNIQUE ← check-out idempotency
  created_at / updated_at
  UNIQUE (employee_id, attendance_date)   att_employee_date_idx
  INDEX (attendance_date, status), (site_id, attendance_date), (check_in_at)
```

**`UNIQUE (employee_id, attendance_date)`** is the primary defence against
duplicate attendance; **`client_event_id` UNIQUE** is the defence against a
replayed offline event. Both are checked by the database, inside the same
transaction that writes the row — not by a `whereExists` anybody could
forget.

**Why `DATETIME` and not `TIMESTAMP`:** MariaDB 10.4.28 runs with
`explicit_defaults_for_timestamp=0`, so the first `NOT NULL timestamp`
column in a table silently gets `ON UPDATE CURRENT_TIMESTAMP`. It rewrote
`check_in_at` on every check-out. The migration docblock says so in place.

**Why there is no `attendance_locations` table:** every GPS reading taken
for attendance already has a home — check-in columns, check-out columns, and
two more pairs in `site_visits`. A fourth table would repeat those values
with nowhere new to put them. It should be introduced only if a requirement
for *multiple* location pings per punch appears, and it would be an append-
only log keyed by the attendance row rather than another copy of a distance.

**`manually_adjusted` has a status value and no writer.** The override
endpoint and its audit trail arrive later; reserving the value now means
that work changes rows without migrating them.

#### `site_visits` shape — as built

```
site_visits
  id
  employee_id       FK → employees   RESTRICT
  project_id        FK → projects    RESTRICT
  site_id           FK → sites       RESTRICT
  started_at        DATETIME, NOT NULL
  ended_at          DATETIME, nullable
  start_latitude    DECIMAL(10,7)   start_longitude   DECIMAL(10,7)
  start_accuracy    DECIMAL(8,2)    start_distance    DECIMAL(10,2)
  end_latitude      DECIMAL(10,7)   end_longitude     DECIMAL(10,7)
  end_accuracy      DECIMAL(8,2)    end_distance      DECIMAL(10,2)
  purpose           VARCHAR(150), NOT NULL     ← why the visit happened
  remarks           VARCHAR(500), nullable
  status            VARCHAR(20)  open | closed   (default open)
  client_event_id      UUID, UNIQUE   ← start idempotency
  end_client_event_id  UUID, UNIQUE   ← end idempotency
  created_at / updated_at
  INDEX (employee_id, started_at), (site_id, started_at)
```

Exactly two points, never a track. Nothing else in the schema records where
anybody was between `started_at` and `ended_at`, which is also what makes
"no continuous location tracking" a property of the data model rather than
a promise in a privacy notice.

---

### 2.5 Time — Phase 6 ✅

| Table | Purpose |
|---|---|
| `timesheets` | **Derived snapshot** of one attendance day: date, working/break/overtime minutes, computed hours, `status`, the `attendance_id` it came from |
| `overtime_requests` | Requested vs approved minutes, reason, status, `payroll_eligible`, workflow id |

**Two rules that shaped these tables:**

1. **A timesheet is copied, never entered.** There is no `approved_by`,
   no approval column and no `created_by` on `timesheets`, because there is
   nothing to approve — the row is a projection of `attendances` and is
   regenerated by `POST /timesheets/generate`. `status` is a property of the
   *day* (`open` / `complete` / `incomplete`), decided from the working-time
   maths, not signed off by anyone. `attendance_id` is `nullOnDelete`: if an
   attendance row is ever removed, the snapshot stays as a record rather
   than taking the timesheet with it.
2. **Only approved overtime is payroll-eligible, and nothing here computes
   pay.** `overtime_requests.payroll_eligible` is a boolean the approval
   transition sets; `requested_minutes` and `approved_minutes` are whole
   minutes (never hours with a fractional precision problem). A future
   payroll phase reads `payroll_eligible = true` rows and applies rates it
   owns — Phase 6 stores facts and stops.

---

### 2.6 Leave — Phase 6 ✅

| Table | Purpose |
|---|---|
| `leave_types` | Configurable: `code` (unique), `entitlement_days`, `carry_forward_enabled`/`carry_forward_limit`, `maximum_days_per_request`, `is_paid`, `requires_document`, `document_deadline_days`, `allow_negative_balance`, `status`, optional `approval_workflow_id` (null = the default chain) |
| `leave_balances` | Per `employee × leave_type × year` (**unique**): `entitlement`, `carry_forward`, `adjustment`, `used`, `pending`; `remaining` is computed, never stored |
| `leave_requests` | Dates, `reason`, `status` (`draft/pending/approved/rejected/cancelled/lop`), `requested_days`, certificate columns, `lop_days`/`lop_reason`/`lop_applied_at` |
| `holidays` | `date`, `name`, `type` (`public/company/site`), `status`, optional `site_id` |
| `approval_workflows` | Definition: `code`, `name`, `is_default`, `status` |
| `approval_workflow_steps` | One row per step: `sequence`, `approver_type` (`reporting_manager/role/permission`), `approver_value` |
| `approval_records` | The **materialised chain** for one submitted subject: polymorphic `subject_type`/`subject_id`, `step`, `approver`, `decision`, `remarks`, `decided_at` |

**What is *not* here, deliberately:**

- **No `leave_documents` table.** The medical certificate is a file on the
  private disk; `leave_requests` carries only `certificate_path`,
  `certificate_original_name`, `certificate_mime`, `certificate_size`,
  `certificate_uploaded_at`, `certificate_due_at` (frozen at submit, so
  changing the setting later cannot retroactively shorten a deadline) and
  `certificate_checked_at` (the scheduler's idempotency marker). Metadata
  that must be queryable lives in the row; bytes never do.
- **No `remaining` column on `leave_balances`.** It is
  `entitlement + carry_forward + adjustment − used − pending`, computed in
  one place, because a stored remainder is a second copy of a number that
  can drift the moment any of its four inputs is written.
- **No status column on `approval_workflow_steps`.** A step is a position in
  a sequence. Where the chain *is* up to is answered by
  `approval_records` — the chain is frozen at submit, so a definition edited
  afterwards cannot rewrite a request already in flight.
- **No FKs on `approval_records.subject_id`.** The subject is polymorphic
  (`leave_request` / `overtime_request`), and a foreign key would need one
  per type with no way to express "whichever". Referential integrity here is
  enforced by the service, which is the only writer.

**Sick leave rule (default `leave.sick_certificate_deadline_days` = 2):**

```
Employee submits a sick leave whose type requires a certificate
        ↓
certificate_due_at = submitted_at + document_deadline_days (type) or the setting
        ↓
EnforceSickCertificateDeadlines — hourly at :17, queued, unique, idempotent
        ↓
Deadline passed and no file?
        ↓
1. status → lop, lop_days = requested_days
2. lop_reason + lop_applied_at recorded
3. the paid leave type's balance reservation is released
4. LeaveConvertedToLop dispatched (after commit) — an event hook, not a
   notification: nothing sends mail, push or FCM in this phase
5. the whole thing runs in one transaction, so a partial conversion is
   impossible and a re-run is a no-op
```

The scheduler runs **server-side**. The phone is never consulted and cannot
extend, skip or postpone a deadline.

---

### 2.7 Payroll & Finance — Phase 8 ✅ (payroll only)

Seven tables. The two that were never payroll-shaped — `expenses`,
`expense_receipts` — are no longer design only: Phase 9 builds them, and
they now have a section of their own (**§2.10**). There is also **no
`salary_slips` table at all**: a slip is a `payrolls` row rendered to PDF on
demand, so there is no stored file to go stale and no path to leak.

| Table | Purpose |
|---|---|
| `payrolls` | One row per employee per month: basic, allowances, overtime, bonus, LOP, deductions, gross, net, `status`, `blocked_reason`. **UNIQUE `(employee_id, payroll_year, payroll_month)`** — the run is the primary key's business meaning |
| `payroll_items` | The line items behind those totals: `type` is `earning` or `deduction`, `code` is one of `basic · allowance · overtime · bonus · adjustment` against `lop · leave_unpaid · loan · advance · other`, with an optional polymorphic `source_type`/`source_id` back to the allowance, adjustment or loan installment that produced it |
| `allowances` | Recurring (`monthly`) or `one_time` pay elements, soft-deleted rather than destroyed |
| `payroll_adjustments` | Bonuses and other deductions for one period; only `approved` rows are computed |
| `loans` | Loan or salary advance: principal, installment amount and count, start date, outstanding balance, status |
| `loan_installments` | The schedule minted at approval. **UNIQUE `(loan_id, sequence)`**; `amount` is what the schedule asks for, `deducted_amount` is what runs have actually taken of it, and `status` is `pending · partially_deducted · deducted · skipped · adjusted`. `payroll_id` names the run that took money from it most recently |
| `salary_certificate_requests` | Purpose, request date, decision, approver, and the one-time `generated_at` |

**Every money column is `DECIMAL(12, 2)`** (rates `DECIMAL(10,4)`/
`DECIMAL(12,4)`, `lop_divisor DECIMAL(8,4)`, quantities `DECIMAL(10,2)` or
`DECIMAL(6,2)`): no `FLOAT`, no `DOUBLE`, anywhere in the schema — a rounding
error in a salary is a payroll error. Status columns are `string(20)` with
model constants, **not** MySQL `ENUM`: adding a status is a deploy, not an
`ALTER`.

**Payroll reads only approved data** — approved attendance, approved and
`payroll_eligible` overtime, leave marked LOP, approved loan installments
(taken inside the run's own transaction), and approved adjustments. It never
writes back into `attendances`, `leave_requests` or `overtime_requests`.

**Repayments are capped, not cancelled.** `payroll.minimum_net_salary` (a
`settings` row, `0` by default) is the floor a run may not pay below:

```
room = gross - (LOP + approved adjustments) - payroll.minimum_net_salary
```

Installments are offered to the run oldest-first, and each takes
`min(amount - deducted_amount, room)`. What fits is written to
`payrolls.loan_deduction` / `advance_deduction` **and** to a `payroll_items`
line; what does not fit stays on the installment — `partially_deducted` when
part of it was taken, still `pending` when nothing was — and is offered to
the next run, together with any older installment whose due date has passed.
Attendance and approved deductions are never rewritten to protect the floor:
they are facts, and if they alone put the row below it the figure is
reported honestly rather than massaged.

Four figures are kept apart, and none is derived from another in a client:

| Figure | Home |
|---|---|
| scheduled installment amount | `loan_installments.amount` |
| actual deducted amount (all runs) | `loan_installments.deducted_amount` |
| remaining on this installment | `amount - deducted_amount` (API: `remaining_amount`) |
| loan outstanding balance | `loans.outstanding_balance` — moves by what was **taken**, never by what was merely due |

Per-run provenance is `payroll_items` (`source_type = loan_installment`),
which is what lets a recalculation give back one month's share of a shared
installment without disturbing another's.

**Locked rows are untouched.** `reviewed`, `processed` and `locked` payroll
rows refuse recalculation outright (409) and are counted as `skipped` by a
second run, so a deduction somebody has already signed off cannot be re-cut
by retuning the floor afterwards. Only `draft` and `calculated` rows release
their claims and take them again.

---

### 2.8 Notifications

| Table | Purpose |
|---|---|
| `notifications` | Laravel's table: database inbox, cross-device persistence |
| `user_notifications` / `device_tokens` | FCM device tokens per user (multi-device) |
| `notification_preferences` | Which events a user wants, per channel |

---

### 2.9 Site Reports — Phase 7 ✅

Seven tables, in two families. The first family is a *personal* record; the
second is an *official* one, and the difference between them is what most
of the schema is encoding.

| Table | Purpose |
|---|---|
| `site_activity_reports` | One person's account of one site-day: `employee_id` (derived from the session), project/site, `report_date`, `work_category`, `work_performed`, `progress_percentage` (0–100), free-text `manpower` / `materials_used` / `equipment_used`, `issues`, `safety_issues`, `remarks`, `latitude` / `longitude` / `gps_accuracy`, `status`, `submitted_at` |
| `site_activity_report_photos` | One row per frame: private `path`, `mime_type`, `size_bytes`, `caption`, `sort_order` |
| `daily_site_reports` | The official site-day: `created_by` (derived), project/site, `report_date`, `total_manpower`, `work_planned`, `work_completed`, `safety_observations`, `delays`, `issues`, `remarks`, `status`, `submitted_at`, `approved_at` *(reserved)* |
| `daily_site_report_manpower` | One row per workforce **category** — free text, not a lookup, not an ENUM |
| `daily_site_report_materials` | One row per consumed line: name, `quantity` `DECIMAL(12,3)`, unit, remarks |
| `daily_site_report_equipment` | One row per plant item: name, quantity, `operating_hours`, `condition`, remarks |
| `daily_site_report_photos` | Identical in shape to the activity table, separate on purpose (see below) |

**Why two shapes.** The activity report is filled in on a phone between two
pours, so its resources are `text` and the row is one insert. The daily
report is read by somebody who was not there, so its resources are child
rows that can be summed, compared between days and corrected one line at a
time. Both rules are real; putting either one on the wrong table would make
one of the two documents unusable.

**Categories are words, not ids.** `daily_site_report_manpower.category` is
`VARCHAR(60)` with no FK and no ENUM: a fixed list would need a re-deploy
the first time a site reports "scaffolders", and a lookup table would put a
master-data module between a supervisor and the line they are recording.
`daily_site_reports.total_manpower` is the derived sum, written from those
rows on save, so the headline number never has to be trusted from a client
that could have computed it differently.

**One official report per site per day** is enforced by
`dsr_site_date_unique` — plus `Rule::unique` scoped to `site_id` in the
request (so the user sees a field error) plus a `QueryException` guard in
the service (so a caller that bypasses the request does not see a 500).
Three layers, because "two documents for the same site-day" is a
correctness failure, not a nuisance.

**The photo tables are separate rather than polymorphic.** A shared
`report_photos` with `reportable_id`/`reportable_type` would make "which
reports may this photograph travel with?" a string comparison inside a
policy instead of a foreign key the database can check — and these two
report families have different policies. Two small tables is the cheaper
lie. Neither carries a URL, and neither stores bytes: `path` is a name
under `storage/app/private/site-report-photos/`.

**No soft deletes** on any of the seven: unlike master data, a report is
an assertion about a day, and quietly removing one is what `status` and a
later approval step are for.

**`approved_at` exists and is never written.** Phase 7 ships submit and
stops; the column means adding approval later is a code change, not a
schema change on a table already holding reports.

---

### 2.10 Expenses — Phase 9 ✅

Three tables, three migrations. An expense claim is an employee's word about
money spent, waiting to become somebody else's money paid out, so the shape
deliberately mirrors `leave_requests` / `overtime_requests`: the approval
columns are the same columns because the same engine writes them.

| Table | Purpose |
|---|---|
| `expense_categories` | The configurable half of a claim: `name`, `code` (**UNIQUE** — the stable handle a seeder and a report key off, and the reason re-seeding updates a row instead of adding a second "Travel"), `description`, `status`, `requires_receipt`, `maximum_amount`. **There is no category CRUD endpoint**: `GET /expense-categories` is read-only, so adding a category is a row, not a deploy |
| `expenses` | One claim: employee (derived from the session), category, optional project / site, `expense_date`, `amount DECIMAL(12,2)`, `currency`, `description`, `status`, `current_approval_step`, the four lifecycle timestamps, `final_approved_by` |
| `expense_receipts` | One row per file of evidence — facts about a *private* file only: `path`, `original_name`, `mime_type`, `size_bytes`, `uploaded_by`. Never a URL, never a disk name in a response, never the bytes |

#### `expense_categories` shape — as built

```
expense_categories
  id
  name                  VARCHAR(100)
  code                  VARCHAR(30) UNIQUE   ← seeder lookup key, report handle
  description           VARCHAR(500) NULL
  status                VARCHAR(20) DEFAULT 'active' INDEX   active | inactive
  requires_receipt      TINYINT(1) DEFAULT 0   ← per category, not global
  maximum_amount        DECIMAL(12,2) NULL      ← NULL = no ceiling, not 0
  created_at / updated_at
```

`requires_receipt` is per category because both halves of the argument are
true of the same system: a taxi fare needs paper, a per-diem allowance does
not, and one boolean cannot answer both. `maximum_amount` is nullable on
purpose — "no ceiling" is a real answer and `0` would read as "nothing may
be claimed".

**Six rows are seeded** by `ExpenseCategorySeeder` (registered in
`DatabaseSeeder`, keyed on `code` so it is safe to re-run):

| Code | Name | `requires_receipt` | `maximum_amount` |
|---|---|---|---|
| `TRAVEL` | Travel | yes | `NULL` — no ceiling |
| `TRANSPORT` | Transport | yes | `NULL` — no ceiling |
| `SITE` | Site Expense | yes | `NULL` — no ceiling |
| `FOOD` | Food | **no** | `1000.00` — the one worked example of a ceiling |
| `ACCOMMODATION` | Accommodation | yes | `NULL` — no ceiling |
| `OTHER` | Other | **no** | `NULL` — no ceiling |

The ceiling on Food is a sample, not a policy this application has an
opinion about: it is a row an operator is expected to change. Every rule in
the table is read back by `ExpenseService` at create, update **and** submit
time, so an operator retiring a category or lowering a ceiling takes effect
on the next write rather than on the next deploy.

#### `expenses` shape — as built

```
expenses
  id
  employee_id            FK → employees             RESTRICT  ← session, never payload
  expense_category_id    FK → expense_categories    RESTRICT
  project_id             FK → projects              RESTRICT  NULL
  site_id                FK → sites                 RESTRICT  NULL
  expense_date           DATE
  amount                 DECIMAL(12,2)
  currency               VARCHAR(3)
  description            VARCHAR(500)
  status                 VARCHAR(20) DEFAULT 'draft' INDEX
                                    draft | pending | approved | rejected | cancelled
  current_approval_step  SMALLINT UNSIGNED NULL     ← the step waiting right now
  approval_workflow_id   FK → approval_workflows    SET NULL  NULL
  submitted_at           DATETIME NULL
  approved_at            DATETIME NULL
  rejected_at            DATETIME NULL
  cancelled_at           DATETIME NULL
  final_approved_by      FK → users                 SET NULL  NULL
  created_at / updated_at
  INDEX (employee_id, expense_date)        exp_emp_date_idx
  INDEX (expense_category_id, status)      exp_cat_status_idx
  INDEX (project_id, expense_date)         exp_project_date_idx
  INDEX (site_id, expense_date)            exp_site_date_idx
```

- **`employee_id` is derived from the authenticated session** and is never
  read from the payload — "claim on behalf of a colleague" is not a feature
  this system has.
- **`project_id` / `site_id` stay nullable** (a taxi home after a late shift
  has no site) and are `restrictOnDelete` rather than `nullOnDelete`: a claim
  that silently loses the site it was booked against stops being auditable.
- **The approval columns mirror `leave_requests` and `overtime_requests`**
  because `ApprovalWorkflowService` writes all three: `current_approval_step`
  is the sequence number of the step waiting now (`NULL` when none is), the
  chain itself lives in `approval_records`, and the definition is frozen into
  those rows at submit — editing EXP-STD later never re-routes a claim
  already in flight.
- **`final_approved_by`, not `approved_by`**: approval here is the *last*
  link of a chain, not the only one. Who acted on each individual step is
  `approval_records.acted_by`.
- **Status is written by `ExpenseService` alone.** There is no endpoint that
  sets an arbitrary status, because `approved` means "the chain was walked
  end to end and every step said yes".

#### `expense_receipts` shape — as built

```
expense_receipts
  id
  expense_id            FK → expenses  CASCADE   ← a receipt cannot outlive its claim
  path                  VARCHAR(500)   private disk name: expense-receipts/{id}/{uuid}.{ext}
  original_name         VARCHAR(255) NULL        ← data for a list to read, never opened with
  mime_type             VARCHAR(100)
  size_bytes            INT UNSIGNED
  uploaded_by           FK → users     SET NULL  NULL
  created_at / updated_at
```

`path` is minted by `ExpenseReceiptStore` from a UUID, never from the
client's filename, under `storage/app/private/expense-receipts/`
(`hrms.storage.expense_receipt_directory`, env
`HRMS_EXPENSE_RECEIPT_DIRECTORY`, default `expense-receipts`). No API
response ever echoes it: the only way to the bytes is
`GET /expenses/{expense}/receipts/{receipt}`, behind `ExpensePolicy`.
`expense_id` cascades because a receipt is meaningless without its claim —
and expenses themselves have **no delete endpoint**, so the lifecycle ends
at `cancelled` or `rejected` and history stays readable.

**Money and status conventions, exactly as everywhere else in this schema:**
`amount` and `maximum_amount` are `DECIMAL(12,2)`, values travel as decimal
strings end to end through `App\Support\Money` and `Money::round()`, and no
money figure is stored, summed or handed back to a client as a float — there
is no `FLOAT`/`DOUBLE` money column in the schema. `status` is `string(20)`
with constants on the model — a vocabulary, not a MySQL `ENUM`.

**Receipt limits** are configuration, not code:
`hrms.storage.expense_receipt_max_kilobytes` (env
`HRMS_EXPENSE_RECEIPT_MAX_KB`, default `5120`) caps a single file, six per
request batch, and `ExpenseService::MAX_RECEIPTS` caps a claim at **10**.
Accepted types are PDF, JPEG, PNG and WebP, validated on the request and
re-checked in the storage layer.

**EXP-STD.** `ApprovalWorkflowSeeder` adds a fourth workflow row,
`EXP-STD` (`subject_type = expense`, the default for the subject), with its
two links:

```
1  Supervisor       reporting_manager   ← resolved from the claimant's own employee row
2  Finance / HR     permission          ← expenses.manage (HR Admin, Payroll Admin, Finance)
```

The second link is a *permission* step rather than a role so a deployment
that renames its finance team still has a chain that works — and Project
Manager, who holds `expenses.approve` but not `expenses.manage`, cannot sign
off the payment.

---

## 3. Relationship Summary

**Implemented (Phase 2) — solid lines exist in the database today:**

```
users ── 1:0..1 ── employees ──*── departments ──*── designations
                        │
                        ├──*── employee_site_assignments *── projects *── sites
                        │                                    │            │
                        │                                    │            ├── shifts
                        │                                    │            └── settings (working hours)
                        │                                    └── project_manager ─┐
                        ├─── reporting_manager (self FK)                          │
                        └─── primary_site / primary_project ──────────────────────┘

users ──*── roles ──*── permissions          (spatie/laravel-permission)
settings  (standalone typed key/value store)
```

**Added in Phase 5 ✅:**

```
employees ──*── attendances *── sites *── projects
                       │  └── shifts
           └──*── site_visits  *── sites *── projects
```

Both use `RESTRICT` toward employees, projects and sites: a day of
attendance is what turns an employee, a site or a project into a historical
record, and hard-deleting any of them should throw rather than erase it. The
one exception is `attendances.shift_id`, which is `nullOnDelete` — a shift
is configuration that may be retired, and it must never be possible to
block the removal of a schedule by a year of old attendance rows that
merely referenced it.

**Added in Phase 6 ✅:**

```
leave_types ──┐
              ├──*── leave_requests *── employees
   (type may point at a workflow)
approval_workflows ──*── approval_workflow_steps
        │
        └──*── approval_records        polymorphic → leave_request | overtime_request
employees ──*── leave_balances *── leave_types
employees ──*── timesheets  *── attendances
employees ──*── overtime_requests
sites ──*── holidays
```

`leave_types.approval_workflow_id` and `approval_workflows` steps are
`nullOnDelete` / independent of requests on purpose: a workflow is
*configuration*, and losing one must not cascade into a request whose
decisions are already recorded in `approval_records`.

`approval_records` has no FK to `leave_requests` or `overtime_requests`
(polymorphic subject, see §2.6). Everything else above uses `RESTRICT`,
except `timesheets.attendance_id`, which is `nullOnDelete`.

**Added in Phase 7 ✅:**

```
employees ──*── site_activity_reports *── projects
                        │                        │
                        ├──*── site_activity_report_photos
                        └──*── sites ────────────┘
users ────*── daily_site_reports *── projects
                        │                        │
                        ├──*── site_id → sites ──┘
                        ├──*── daily_site_report_manpower
                        ├──*── daily_site_report_materials
                        ├──*── daily_site_report_equipment
                        └──*── daily_site_report_photos
```

Both families point at `projects` and `sites` with `RESTRICT`: a site-day
that has been written about is what turns a site or a project into
historical record. The photo tables are the only `cascadeOnDelete` here,
and only in the direction you would want — a photograph cannot outlive the
report it is evidence for.

`site_activity_reports.employee_id` and `daily_site_reports.created_by` are
both derived server-side from the bearer token. Neither column is read from
a payload anywhere, so no migration, request rule or policy has to defend
against a client claiming a different author.

**Circular-reference note:** `employees.primary_project_id → projects` and
`projects.project_manager_id → employees` are mutually referential, as are
`employees.primary_site_id → sites` and `sites.site_manager_id → employees`. Both
pairs are created across two migrations (see §6) to avoid a circular dependency
at schema-creation time. They are *not* an architectural loop — one side is a
"current placement" pointer, the other is a staffed-role pointer.

**Built in Phase 8 (see §2.7):**

```
employees ──*── payrolls *── payroll_items
           ├──*── allowances
           ├──*── payroll_adjustments
           ├──*── salary_certificate_requests
           └──*── loans *── loan_installments
                        │
                        └── loan_installments.payroll_id → payrolls   (provenance, nullable)
```

`payroll_items.source_type/source_id` points back at an allowance, an
adjustment or an installment. It is deliberately **not** a foreign key: it is
the answer to "where did this line come from?", not an ownership edge, and a
polymorphic pair cannot carry one without a constraint the schema would never
check.

**Built in Phase 9 (see §2.10):**

```
employees ──*── expenses *── expense_receipts
                      │
                      ├──*── expense_categories
                      ├──*── projects / sites          (both nullable, RESTRICT)
                      ├──*── approval_workflows        (nullable, SET NULL)
                      └── * ── approval_records        polymorphic → expense
```

`expenses` takes the same three edges `leave_requests` does, for the same
reasons: `RESTRICT` toward the employee, the category, the project and the
site, because a claim that outlives what it was booked against is still a
claim; `SET NULL` toward the workflow and the final approver, because a
retired definition must not take a settled claim's history with it. The
receipt table is the second and last `cascadeOnDelete` in the schema after
the report photos — a receipt cannot outlive the claim it is evidence for.

**Still designed but not yet created (Phases 10–12):**

```
employees ──*── employee_documents
           ├──*── employee_trainings *── trainings
           ├──*── asset_assignments *── assets
           └──*── notifications
```

**Relationships deliberately *not* added:** there is no
`employees.many-to-many sites` pivot, no `departments → projects`, and no
`designations → sites`. The append-only `employee_site_assignments` table is the
single source of truth for placement, so a second path would only create a way
for the two to disagree.

---

## 4. Indexing Strategy

| Pattern | Index |
|---|---|
| Every FK | Single-column index |
| Attendance by day | `(attendance_date, status)` |
| Attendance by employee + date | `UNIQUE (employee_id, attendance_date)` |
| Attendance by site + day | `(site_id, attendance_date)` |
| Offline sync idempotency | `UNIQUE (client_event_id)`, `UNIQUE (check_out_client_event_id)` |
| Site lookup | `(project_id, status)` |
| Open assignment lookup | `(employee_id, status, end_date)` |
| Leave approval queue | `(status, start_date)` + `(leave_type_id, status)` |
| Overlap / entitlement check | `(employee_id, start_date, end_date)` |
| Certificate deadline sweep | `(certificate_due_at)` — the scheduler's only scan |
| Holiday calendar by window | `(date, type)` and `(site_id, date)` |
| Timesheet window | **UNIQUE** `(employee_id, timesheet_date)` + `(timesheet_date, status)` |
| Overtime queue / payroll feed | `(status, overtime_date)` + `payroll_eligible` |
| Approval chain lookup | **UNIQUE** on the step's `(workflow_id, sequence)` and on the record's subject + step; `(subject_type, subject_id, status)` |
| Activity report by author / site / project | `sar_emp_date_idx` `(employee_id, report_date)` · `sar_site_date_idx` · `sar_project_date_idx`, plus an index on `status` |
| Official report lookup | **UNIQUE** `dsr_site_date_unique` `(site_id, report_date)` + `dsr_project_date_idx` + `dsr_creator_date_idx` |
| Report photographs in order | `sarp_…` / `dsrp_…` `(…_report_id, sort_order)` — and the same pair on the three child tables |
| Document expiry | `(expiry_date)` |
| Payroll period | **UNIQUE** `payrolls (employee_id, payroll_year, payroll_month)` + `(payroll_year, payroll_month, status)` |
| Loan schedule | **UNIQUE** `loan_installments (loan_id, sequence)` + `(status, due_date)` — the second one answers "what still owes?" now that a row can be `pending` with an overdue due date or `partially_deducted` with a remainder |
| Run / period lookups | `payrolls`, `allowances`, `payroll_adjustments` on their own period columns |
| Expense claims | `expenses (employee_id, expense_date)` · `(expense_category_id, status)` · `(project_id, expense_date)` · `(site_id, expense_date)` — "my claims", "what is pending in this category", and the two date-range views a project or a site asks for, plus the indexed `status` default |
| Expense category pickers | **UNIQUE** `expense_categories (code)` + `(status)` — the active list a claim form draws, and the key a re-seed looks a row up by |

**N+1 prevention:** all list endpoints eager-load their relations via `with()`, and are
verified with `DB::enableQueryLog()` during testing.

---

## 5. Seed Data (Phase 2)

All counts below are **live and verified** against `hrms_laravel` after
`php artisan migrate:fresh --seed`.

| Seeded | Count | Seeder |
|---|---|---|
| Roles | 10 | `RoleSeeder` |
| Permissions | **73** | `PermissionSeeder` |
| Role → permission grants | **364** | `RolePermissionSeeder` |
| Settings | **17** | `SettingSeeder` |
| Approval workflows | 4 — `LEAVE-STD` (default for leave, 3 steps), `LEAVE-FAST` (1 step), `OT-STD` (default for overtime, 3 steps), `EXP-STD` (default for expense, 2 steps) | `ApprovalWorkflowSeeder` |
| Approval workflow steps | 9 — reporting manager → role → role / permission; `EXP-STD` adds reporting manager → permission (`expenses.manage`) | `ApprovalWorkflowSeeder` |
| Expense categories | 6 — `TRAVEL`, `TRANSPORT`, `SITE`, `ACCOMMODATION` (receipt required), `FOOD` (sample ceiling `1000.00`, no receipt), `OTHER` (no receipt) | `ExpenseCategorySeeder` |
| Leave types | 5 — `AL` Annual, `SL` Sick (certificate, 2-day deadline), `EL` Emergency, `UL` Unpaid (may go negative), `OTH` Other | `LeaveTypeSeeder` |
| Shifts | 4 — General, Morning, Evening, Night | `DevelopmentDataSeeder` |
| Departments | 4 *(development sample)* | `DevelopmentDataSeeder` |
| Designations | 8 *(development sample)* | `DevelopmentDataSeeder` |

Permission counts by phase: Phase 2 seeded **40**; Phase 4 added
`employees.salary.view` → 41; Phase 6 added 11
(`approvals.view/manage`, `leave.balance.view/manage`, `holidays.manage`,
`timesheets.view/manage`, `overtime.view/create/approve/manage`) → **51**, and
retired `leave.request` in favour of `leave.create`; Phase 7 added
8 (`site_activity_reports.{view,create,update}` and
`daily_site_reports.{view,create,update,manage,pdf}`) → **59**; **Phase 8
added 11 → 70** (`loans.{view,create,approve,manage}`,
`salary_slips.{view,manage}`, `salary_certificates.{view,manage}` and the
three payroll grants that were missing — `payroll.process`, `payroll.lock`,
`payroll.summary.view`; `payroll.view` and `payroll.manage` already existed).
**Phase 9 added 3 → 73**: `expenses.create`, `expenses.update` and
`expenses.receipts.view` — the other three expense grants
(`expenses.view`, `expenses.approve`, `expenses.manage`) already existed at
Phase 8 and were already in the catalogue.
Phase 5 added no permission but granted the existing `attendance.view` to
`Employee`. Grants grew 168 → 236 → 278 → **332** → **364** (Phase 9 adds
32: the expense family reaching ten roles at `expenses.view`). The number is
rows in `role_has_permissions`, so Super Admin's `['*']` counts as all 73.
`PermissionSeeder::flat()` is the single source of truth and `RbacTest`
asserts the seeded count matches it exactly, so this number cannot drift
silently.

**The full expense matrix — all six grants, role by role** (counts verified
against `role_has_permissions`):

| Permission | Roles | Held by |
|---|---|---|
| `expenses.view` | **10** | Employee, Finance, HR Admin, HR Executive, Management, Payroll Admin, Project Manager, Site Engineer, Site Supervisor, Super Admin — every role, because a list endpoint is no use to a role that cannot open it |
| `expenses.create` | **7** | Employee, HR Admin, HR Executive, Project Manager, Site Engineer, Site Supervisor, Super Admin |
| `expenses.update` | **9** | Employee, Finance, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Engineer, Site Supervisor, Super Admin |
| `expenses.approve` | **7** | Finance, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Supervisor, Super Admin |
| `expenses.manage` | **4** | Finance, HR Admin, Payroll Admin, Super Admin |
| `expenses.receipts.view` | **7** | Finance, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Supervisor, Super Admin |

**Who holds the Phase 9 three.** `expenses.create` reaches seven roles —
Employee, HR Admin, HR Executive, Project Manager, Site Engineer, Site
Supervisor, Super Admin: every role that is expected to file a claim of its
own, and nobody else. Finance, Payroll Admin and Management
deliberately do not get it, the way they do not get `leave.create`.
`expenses.update` widens to nine (adds Finance and Payroll Admin) because
"edit" covers two different acts: correcting your own draft, and the
back-office counterpart of `expenses.manage` — Payroll Admin and Finance are
consumers of claims rather than filers of them, so they hold `.update`
without `.create`. `expenses.receipts.view` goes to seven (Finance, HR
Admin, HR Executive, Payroll Admin, Project Manager, Site Supervisor, Super
Admin): everybody who may read a claim they did not file, because being
shown a list of claims and being handed the invoice behind one are different
acts. **Site Engineer is the deliberate exclusion** — they file and edit
their own claims but have no `expenses.approve` and no `.receipts.view`, and
Employee reads their own receipts through ownership instead of the grant.
`expenses.manage` stays with its four (Finance, HR Admin, Payroll Admin,
Super Admin) and is the permission `EXP-STD`'s last link resolves to.

**Who holds the Phase 8 eleven.** `payroll.view` reaches Super Admin, HR
Admin, Payroll Admin, Finance, Management **and Employee** — the last of
those only ever sees their own rows, because `PayrollController` narrows to
the caller rather than trusting the grant. `payroll.manage`/`payroll.process`
are Super Admin, HR Admin and Payroll Admin; **`payroll.lock` is Payroll Admin
alone**, so the one irreversible button has one owner.
`payroll.summary.view` adds Finance and Management, who get totals and no
rows. `salary_slips.*` is Employee, HR Admin, Payroll Admin and Finance —
"a payslip please" and "show me the ledger" are different questions.
`salary_certificates.*` is Employee (ask, read, cancel their own), HR Admin
and Payroll Admin (decide). `loans.{view,create}` is the widest of the new
family — every role that has an employee can ask for an advance — but
**`loans.approve` and `loans.manage` are Super Admin, HR Admin and Payroll
Admin only**, and nobody may approve their own. Project Manager, Site
Engineer and Site Supervisor get loans and **no payroll at all**, as Phase 7
left them.

**Who holds the Phase 7 eight.** `site_activity_reports.view` includes
`Employee` — recording your own day is the reason the module exists.
`daily_site_reports.view` and `.pdf` deliberately **exclude** `Employee`
(and Payroll Admin and Finance): the official record about a site-day is
read by the people who prepare and manage it — Super Admin, HR Admin, HR
Executive, Project Manager, Site Engineer, Site Supervisor and Management.
`.create`/`.update` go to Project Manager, Site Engineer and Site
Supervisor; `.manage` to Project Manager alone.

Re-running the seeders brings an older dev database up to date without
touching anything else — they are `firstOrCreate` / `syncPermissions` only.

> The sick-leave deadline default comes from
> `settings.leave.sick_certificate_deadline_days` (**2**) and is overridden
> per type by `leave_types.document_deadline_days` when that is `> 0`.

> The heading on an exported daily report comes from
> `settings.reporting.company_name` (**`HRMS`** by default, group
> `reporting`) and falls back to `config('app.name')` — one of the first
> settings a deployment is likely to want to change.
>
> The four payroll settings are `payroll.lop_divisor_mode` (**`fixed`**),
> `payroll.lop_divisor` (**`30`**), `payroll.overtime_rate_multiplier`
> (**`1.5`**) and `payroll.minimum_net_salary` (**`0`**) — see
> `docs/SECURITY.md` on why the multiplier is a generic engine parameter and
> not a statutory rate, and why the floor is a setting rather than a 0 in
> the calculation.

> The company's currency is a **pair of rows**: `system.currency` (**`AED`**)
> is what payroll, the salary-slip and certificate PDFs, `PayrollResource`,
> `LoanResource` and a new expense claim's default all read, and
> `system.supported_currencies` (**`["AED"]`**, `json`) is the list a claim
> may be filed in — one entry offers no choice, two or more make a menu, and
> an empty list switches the membership rule off. Both are served to the app
> by `GET /api/v1/client-settings` (allow-listed, read-only), so a deployment
> changes a row rather than shipping a build. `SettingSeeder` uses
> `updateOrCreate`, so re-seeding **does** reset these values to the shipped
> defaults: tune them after seeding.

> ⚠️ `DevelopmentDataSeeder` contains **sample structure only**. It creates no
> employees, users, salaries or assignments. Every row it writes is labelled
> *"Development sample data."* in its `description`.
>
> All eight seeders are **idempotent** (`firstOrCreate` / `updateOrCreate`), so
> `php artisan db:seed` can be re-run safely without duplicating rows.

---

## 6. Migration History

| Phase | Migrations | Status |
|---|---|---|
| 1 | `0001_01_01_*` framework tables + `2026_09_26_170015` Sanctum tokens | ✅ |
| 2 | Core schema — 10 migrations, see below | ✅ |
| 3 | `2026_09_27_000010` — `users.status` (indexed `active`/`inactive`) | ✅ |
| 4 | **None.** Controllers, Form Requests, Resources, services and six policies on the tables Phase 2 already created | ✅ |
| 5 | `2026_09_27_110001_create_attendances_table`, `2026_09_27_110002_create_site_visits_table` | ✅ |
| 6 | `2026_09_27_120001` … `2026_09_27_120009` — nine migrations, see below | ✅ |
| 7 | `2026_09_28_130001` … `2026_09_28_130007` — seven migrations, see below | ✅ |

### Phase 2 migrations (all `Ran`)

| # | Migration | Creates |
|---|---|---|
| 1 | `2026_09_26_185514_create_permission_tables` | `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` |
| 2 | `2026_09_27_000001_create_settings_table` | `settings` |
| 3 | `2026_09_27_000002_create_departments_table` | `departments` |
| 4 | `2026_09_27_000003_create_designations_table` | `designations` |
| 5 | `2026_09_27_000004_create_shifts_table` | `shifts` |
| 6 | `2026_09_27_000005_create_projects_table` | `projects` |
| 7 | `2026_09_27_000006_create_employees_table` | `employees` |
| 8 | `2026_09_27_000007_create_sites_table` | `sites` |
| 9 | `2026_09_27_000008_create_employee_site_assignments_table` | `employee_site_assignments` |
| 10 | `2026_09_27_000009_add_deferred_foreign_keys` | FKs deferred to break circular refs |

### Phase 5 migrations (all `Ran`)

| # | Migration | Creates | Why it looks like this |
|---|---|---|---|
| 11 | `2026_09_27_110001_create_attendances_table` | `attendances` | `dateTime`, not `timestamp`, for all four time columns — see §2.4 |
| 12 | `2026_09_27_110002_create_site_visits_table` | `site_visits` | Two point-pairs of coordinates, one `purpose`, two idempotency keys |

### Phase 6 migrations (all `Ran`)

| # | Migration | Creates | Why it looks like this |
|---|---|---|---|
| 13 | `2026_09_27_120001_create_approval_workflows_table` | `approval_workflows` | **Definition first**: `subject_type` (`leave` / `overtime`) + `code` is unique, and `is_default` is unique *per subject type* — enforced in the controller, because a partial unique index would not survive a seeder that re-points the default |
| 14 | `2026_09_27_120002_create_approval_workflow_steps_table` | `approval_workflow_steps` | `sequence` + a `name` + `approver_type`/`approver_role`/`approver_permission`. **No status column** — a step is a position, not a state |
| 15 | `2026_09_27_120003_create_leave_types_table` | `leave_types` | Soft-deletable configuration. `approval_workflow_id` is `nullOnDelete`, so retiring a workflow falls back to the default chain instead of orphaning a type |
| 16 | `2026_09_27_120004_create_leave_balances_table` | `leave_balances` | **UNIQUE (employee, type, year)** — the balance is the one row the whole feature locks; a second one would make "the" balance ambiguous |
| 17 | `2026_09_27_120005_create_leave_requests_table` | `leave_requests` | `dateTime` for every timestamp (not `timestamp`), `decimal(6,2)` for day counts because a half day is real, and the certificate + LOP columns on the row rather than a side table |
| 18 | `2026_09_27_120006_create_holidays_table` | `holidays` | `site_id` nullable and `NULL` means *company-wide*; the duplicate key is `(date, type, site_id)` checked with a real `whereNull` |
| 19 | `2026_09_27_120007_create_timesheets_table` | `timesheets` | **UNIQUE (employee, timesheet_date)** and `attendance_id` `nullOnDelete` — a snapshot outlives its source |
| 20 | `2026_09_27_120008_create_overtime_requests_table` | `overtime_requests` | Whole minutes only; `payroll_eligible` is indexed because it is the future payroll feed's filter |
| 21 | `2026_09_27_120009_create_approval_records_table` | `approval_records` | Polymorphic `subject_type`/`subject_id` with **no FK** — the service is the only writer (see §2.6) |

### Phase 7 migrations (all `Ran`)

| # | Migration | Creates | Why it looks like this |
|---|---|---|---|
| 22 | `2026_09_28_130001_create_site_activity_reports_table` | `site_activity_reports` | `employee_id` is derived from the session and **never read from the payload**; resources are free `text` so the row is one insert; three `(…, report_date)` indexes because every filter is a date range |
| 23 | `2026_09_28_130002_create_site_activity_report_photos_table` | `site_activity_report_photos` | Private `path` only — no URL column, no `disk`, no bytes; `cascadeOnDelete` so a photograph cannot outlive its report |
| 24 | `2026_09_28_130003_create_daily_site_reports_table` | `daily_site_reports` | `created_by` (not `employee_id`) because this document is *authored*; **`dsr_site_date_unique`** is the business rule in the database; `approved_at` reserved for a later phase |
| 25 | `2026_09_28_130004_create_daily_site_report_manpower_table` | `daily_site_report_manpower` | `category` is free text — no ENUM, no lookup — so the vocabulary belongs to the site rather than to the schema |
| 26 | `2026_09_28_130005_create_daily_site_report_materials_table` | `daily_site_report_materials` | `DECIMAL(12,3)`: three tonnes and 250 kg are both real quantities on the same day. **No stock, no valuation** — this is not an inventory |
| 27 | `2026_09_28_130006_create_daily_site_report_equipment_table` | `daily_site_report_equipment` | `operating_hours` is a nullable meter reading; **no asset tag, no maintenance** — this is not an asset register |
| 28 | `2026_09_28_130007_create_daily_site_report_photos_table` | `daily_site_report_photos` | Separate from the activity photos rather than polymorphic, so "which report may this travel with?" is a foreign key, not a string comparison |

After Phase 7: **41 tables**, **33 migrations** (3 framework, 1 Sanctum,
10 Phase 2, 1 Phase 3, 2 Phase 5, 9 Phase 6, 7 Phase 7).

### Phase 8 migrations (all `Ran`)

| # | Migration | Creates | Why it looks like this |
|---|---|---|---|
| 29 | `2026_09_29_140001_create_payrolls_table` | `payrolls` | **UNIQUE `(employee_id, payroll_year, payroll_month)`** — one run per person per month is a database rule, not a service's good intentions. Every figure is `DECIMAL(12,2)`; `lop_divisor DECIMAL(8,4)` is stamped onto the row so a slip can still explain itself after the setting changes. `blocked_reason` is what a `draft` row says instead of a zero |
| 30 | `2026_09_29_140002_create_payroll_items_table` | `payroll_items` | The audit trail of one run. `source_type`/`source_id` point back at the allowance, adjustment or installment that produced the line, so "why is this 1,500?" is answerable without a comment field. **No FK on the polymorphic pair** — it is provenance, not ownership |
| 31 | `2026_09_29_140003_create_allowances_table` | `allowances` | `softDeletes()`: an allowance last year's payroll already used may not vanish from history. `frequency` plus an optional `payroll_year/month` covers both the recurring and the one-off case in one table |
| 32 | `2026_09_29_140004_create_payroll_adjustments_table` | `payroll_adjustments` | A bonus that nobody approved is not a bonus. `status` defaults to `pending` and only `approved` rows are ever read by the calculator; the approver and the three timestamps are columns because "who allowed this?" must survive the user who did it |
| 33 | `2026_09_29_140005_create_loans_table` | `loans` | `installment_amount` is stored, not re-derived: the split agreed at approval is the split that is repaid. `outstanding_balance` is denormalised on purpose — the figure a borrower asks for must not be a SUM over rows a pay-run may be writing |
| 34 | `2026_09_29_140006_create_loan_installments_table` | `loan_installments` | **UNIQUE `(loan_id, sequence)`** and a nullable `payroll_id`: together they are the "not twice" guarantee — the run that deducted a row is recorded on the row it deducted |
| 35 | `2026_09_29_140007_create_salary_certificate_requests_table` | `salary_certificate_requests` | No document column. The PDF is rendered from this row on demand, so `generated_at` is the only trace and there is no file to expire or path to leak |
| 36 | `2026_09_29_140008_add_deducted_amount_to_loan_installments_table` | *(adds a column)* | `loan_installments.deducted_amount DECIMAL(12,2) DEFAULT 0` — how much of a scheduled installment pay has actually taken. A repayment that would push net salary below `payroll.minimum_net_salary` takes only the part that fits and leaves the rest outstanding, which a schema with one all-or-nothing status could not say. The backfill sets `deducted_amount = amount` on every row already `deducted`, so a pre-existing full repayment cannot look untouched and be collected twice |

**48 tables** in `hrms_laravel` at the close of Phase 8, across **41
migrations** (3 framework,
1 Sanctum, 10 Phase 2, 1 Phase 3, 2 Phase 5, 9 Phase 6, 7 Phase 7, 7 Phase 8,
1 repayment floor).

### Phase 9 migrations (all `Ran`)

| # | Migration | Creates | Why it looks like this |
|---|---|---|---|
| 37 | `2026_09_29_150001_create_expense_categories_table` | `expense_categories` | **UNIQUE `code`** is what lets the seeder re-run without duplicating a category it already wrote, and what a report keys off when a name is reworded. `requires_receipt` is per category, `maximum_amount` is `DECIMAL(12,2)` and **nullable — `NULL` means "no ceiling", which is not `0`**. `status` defaults to `active` and is indexed: retiring a category must be one row, not one deploy |
| 38 | `2026_09_29_150002_create_expenses_table` | `expenses` | The approval columns copied from `leave_requests` / `overtime_requests`, because `ApprovalWorkflowService` writes all three: `current_approval_step` + a nullable `approval_workflow_id` + the four lifecycle timestamps. `final_approved_by` rather than `approved_by`, because approval is the last link of a chain. `employee_id` is `RESTRICT` and derived from the session; `project_id`/`site_id` are nullable `RESTRICT` so a claim cannot silently lose the place it was booked against; **`amount DECIMAL(12,2)`**, and four `(…, expense_date)` / `(…, status)` composites for the list, the summary and the two date-range views |
| 39 | `2026_09_29_150003_create_expense_receipts_table` | `expense_receipts` | Facts about a **private** file and nothing else: `path` is a server-minted name under `expense-receipts/{expenseId}/`, never a URL; `original_name` is data for a list to read, never something anything opens with. `cascadeOnDelete` on `expense_id` — a receipt cannot outlive the claim it is evidence for — and `SET NULL` on `uploaded_by` |

**51 tables** total in `hrms_laravel`, across **44 migrations** (3 framework,
1 Sanctum, 10 Phase 2, 1 Phase 3, 2 Phase 5, 9 Phase 6, 7 Phase 7, 7 Phase 8,
1 repayment floor, 3 Phase 9). The Phase 8 close was **48 tables / 41
migrations**; Phase 9 adds exactly three of each, and no earlier migration
was touched.

### Why two foreign keys are "deferred"

Two relationships are circular and cannot be declared while their target table
is still being created:

```
employees.primary_project_id ──→ projects      (employees created after projects)
projects.project_manager_id  ──→ employees     (projects created before employees)

employees.primary_site_id    ──→ sites         (employees created before sites)
sites.site_manager_id        ──→ employees     (sites created after employees)
```

Migration `2026_09_27_000009_add_deferred_foreign_keys` adds the two
*employee-side* constraints (`employees.primary_site_id`, `projects.project_manager_id`)
after both tables exist. The reverse direction was already declarable, so no
constraint is missing — `information_schema` confirms **21 foreign keys** in total.

---

## 7. Foreign Key & Cascade Policy

Every cascade choice is deliberate. **No historical HR record is ever destroyed
by a cascade.**

| Relationship | ON DELETE | Reasoning |
|---|---|---|
| `employee_site_assignments` → `employees` / `projects` / `sites` | **RESTRICT** | Append-only history. Hard-deleting a project or site that has assignment history is blocked outright. |
| `sites` → `projects` | **RESTRICT** | A site cannot exist without its project. |
| `employees.reporting_manager_id` → `employees` | `SET NULL` | Clearing a reporting line must never delete the report. |
| `employees.project_manager_id` / `project_manager_id` → `employees` | `SET NULL` | Manager removed → reference cleared, project survives. |
| `employees.department_id` / `designation_id` | `SET NULL` | Org structure is reorganised often; employees must survive. |
| `employees.user_id` → `users` | `SET NULL` | The HR record outlives the login account. |
| `sites.site_manager_id` / `site_supervisor_id` | `SET NULL` | Staffing changes, not data deletion. |
| `sites.working_hours_setting_id` → `settings` | `SET NULL` | Config reference, falls back to the global default. |
| `roles`/`permissions` pivot tables | `CASCADE` | RBAC pivots are derived data, safe to rebuild. |
| `site_activity_report_photos` → `site_activity_reports` | **CASCADE** | A photograph is evidence *of* a report; it has no meaning alone. The only cascade in the schema that removes a record somebody wrote. |
| `daily_site_report_photos` / `_manpower` / `_materials` / `_equipment` → `daily_site_reports` | **CASCADE** | Child rows of one document, removed with it. |
| `site_activity_reports` / `daily_site_reports` → `employees` / `users` / `projects` / `sites` | **RESTRICT** | A site-day that has been reported on is what makes a site or project historical. |
| `expenses` → `employees` / `expense_categories` / `projects` / `sites` | **RESTRICT** | A claim against money spent cannot outlive the person, the category or the place it was spent at. `project_id`/`site_id` are nullable but still `RESTRICT`: losing a site silently would break the audit trail. |
| `expenses.approval_workflow_id` → `approval_workflows` · `expenses.final_approved_by` → `users` | `SET NULL` | Configuration and people both change; the claim and its materialised chain in `approval_records` stay readable either way. |
| `expense_receipts.expense_id` → `expenses` | **CASCADE** | Evidence cannot outlive the claim it is evidence for. Expenses themselves have no delete endpoint, so in practice nothing reaches the cascade. |
| `expense_receipts.uploaded_by` → `users` | `SET NULL` | Who attached a file is provenance, not ownership of it. |

**Soft deletes** (`deleted_at`) are used on master data where recoverability
matters: `departments`, `designations`, `employees`, `projects`, `sites`, `shifts`.
`employee_site_assignments` and `settings` are **not** soft-deleted — assignments
are immutable history, settings are a small config table. **None of the seven
Phase 7 tables is soft-deleted either**: a report is an assertion about a day,
and retracting one is what `status` and a later approval step are for.

> **Note:** a *soft* delete does not fire any FK rule, so references stay intact
> and the record can be restored. Only `forceDelete()` triggers `SET NULL` /
> `RESTRICT`. Both behaviours are covered by tests.

---

## 8. Implemented Indexes

| Table | Index | Purpose |
|---|---|---|
| `settings` | `UNIQUE (key)` | Config lookups, no duplicate keys |
| `settings` | `(group)` | Settings screen grouping |
| `departments` | `UNIQUE (code)` | Short stable reference |
| `designations` | `UNIQUE (code)` | Short stable reference |
| `designations` | `(department_id, status)` | "Active titles in this dept" |
| `employees` | `UNIQUE (employee_code)` | Business key |
| `employees` | `UNIQUE (email)` | Login / contact identity |
| `employees` | `UNIQUE (user_id)` | Enforces 1:1 with `users` |
| `employees` | `(department_id, employment_status)` | Headcount reports |
| `employees` | `(joining_date)` | Join-date ranges |
| `projects` | `UNIQUE (code)` | Short stable reference |
| `projects` | `(status)` | Portfolio filters |
| `sites` | `UNIQUE (code)` | Short stable reference |
| `sites` | `(project_id, status)` | Sites per project |
| `shifts` | `UNIQUE (code)` | Short stable reference |
| `shifts` | `(crosses_midnight)` | Overnight-shift duration queries |
| `employee_site_assignments` | `(employee_id, status)` | Find a person's current site |
| `employee_site_assignments` | `(site_id, start_date)` | Who was on this site, when |
| `attendances` | `UNIQUE (employee_id, attendance_date)` — `att_employee_date_idx` | One day, one row — duplicate check-in is refused by the database |
| `attendances` | `UNIQUE (client_event_id)` | Offline replay cannot insert twice |
| `attendances` | `UNIQUE (check_out_client_event_id)` | Same, for the closing punch |
| `attendances` | `(attendance_date, status)` — `att_date_status_idx` | Daily present/late/incomplete reports |
| `attendances` | `(site_id, attendance_date)` — `att_site_date_idx` | Who was on this site, on this day |
| `attendances` | `(check_in_at)` — `att_check_in_idx` | Movement timelines and arrival ordering |
| `attendances` | `(status)` | The status default is indexed as well as the composite |
| `site_visits` | `UNIQUE (client_event_id)`, `UNIQUE (end_client_event_id)` | Start and end each replay safely |
| `site_visits` | `(employee_id, started_at)` — `sv_emp_started_idx` | "Where was this person today?" |
| `site_visits` | `(site_id, started_at)` — `sv_site_started_idx` | Visits to a site, by date |
| `site_activity_reports` | `(employee_id, report_date)` — `sar_emp_date_idx` | "What did I file?" — the activity list's own filter |
| `site_activity_reports` | `(site_id, report_date)` — `sar_site_date_idx` | Who reported this site, in this window |
| `site_activity_reports` | `(project_id, report_date)` — `sar_project_date_idx` | Project-wide date range |
| `site_activity_reports` | `(status)` | The status dropdown |
| `daily_site_reports` | **UNIQUE** `(site_id, report_date)` — `dsr_site_date_unique` | One official document per site-day, in the database rather than merely intended |
| `daily_site_reports` | `(project_id, report_date)` — `dsr_project_date_idx` | Project window |
| `daily_site_reports` | `(created_by, report_date)` — `dsr_creator_date_idx` | "What did I prepare?" |
| `site_activity_report_photos` | `(site_activity_report_id, sort_order)` — `sarp_report_order_idx` | Gallery order without a `ORDER BY id` coincidence |
| `daily_site_report_photos` | `(daily_site_report_id, sort_order)` — `dsrp_report_order_idx` | Same |
| `daily_site_report_manpower` / `_materials` / `_equipment` | `(…_report_id, sort_order)` | The three child sets, in the order they were entered |
| `payrolls` | **UNIQUE** `(employee_id, payroll_year, payroll_month)` | One run per person per month — the same rule the service enforces, held by the database as well |
| `payrolls` | `(payroll_year, payroll_month, status)` — `payroll_period_status_idx` | "September, everything not locked" — the list and the summary both ask this |
| `payroll_items` | `(payroll_id, type)` — `pi_payroll_type_idx` | Splitting a slip into earnings and deductions without a `GROUP BY` sort |
| `payroll_items` | `(source_type, source_id)` — `pi_source_idx` | Back from a line to the allowance or installment that produced it |
| `allowances` | `(employee_id, status)` · `(payroll_year, payroll_month)` | "What does this person get this month?" |
| `payroll_adjustments` | `(payroll_year, payroll_month, status)` | Which bonuses are approved for the run about to be processed |
| `loans` | `(employee_id, status)` · `(status, start_date)` | A borrower's own debts, and the approved ones due to start |
| `loan_installments` | **UNIQUE** `(loan_id, sequence)` — `li_loan_seq_uk` | A schedule cannot grow a second "payment 4" |
| `loan_installments` | `(status, due_date)` · `(payroll_id)` | What is due next, and what each run has already taken |
| `salary_certificate_requests` | `(employee_id, status)` | "My requests", and the pending queue a desk works through |
| `expense_categories` | **UNIQUE** `(code)` · `(status)` | Re-seeding updates a row instead of adding a second "Travel", and the claim form asks for the active ones |
| `expenses` | `(employee_id, expense_date)` — `exp_emp_date_idx` · `(expense_category_id, status)` — `exp_cat_status_idx` | "My claims", and "what is pending in this category" |
| `expenses` | `(project_id, expense_date)` — `exp_project_date_idx` · `(site_id, expense_date)` — `exp_site_date_idx` · `(status)` | The two date-range views a project or a site asks for, plus the status filter the list and the summary both apply |
| `expense_receipts` | `(expense_id)` (the FK) | One claim's evidence in order; receipts are never listed on their own |

Composite indexes were chosen for the two filters that appear together most
often in HR reports: *department × status* and *site × start date*. The two
Phase 5 tables add the two that appear together in every attendance query:
*employee × date* (which is also the uniqueness rule) and *site × date*.

**N+1 prevention:** all list endpoints eager-load their relations via `with()`, and are
verified with `DB::enableQueryLog()` during testing.

---

## 9. Phase 2 Tables — Full Column Reference

> The Phase 5 tables (`attendances`, `site_visits`) are documented in full in
> §2.4 above, alongside their design reasons. The Phase 7 report tables are
> documented in full in **§2.9** — and, column by column, in the docblocks of
> their own migrations. The three Phase 9 expense tables are documented in
> full in **§2.10**.

```
settings
  id              BIGINT PK
  key             VARCHAR(120) UNIQUE      -- "attendance.grace_period_minutes"
  value           TEXT NULL                -- raw; typed via Setting::castValue()
  type            VARCHAR(20)              -- string|integer|boolean|decimal|json|date|time
  group           VARCHAR(40) INDEX        -- attendance|leave|notification|working_hours|system
  label           VARCHAR(150)
  description     VARCHAR(500) NULL
  is_editable     TINYINT(1)
  created_at, updated_at

departments
  id, name VARCHAR(120), code VARCHAR(30) UNIQUE, description VARCHAR(500) NULL,
  status VARCHAR(20) DEFAULT 'active', created_at, updated_at, deleted_at

designations
  id, department_id FK→departments NULL ON DELETE SET NULL, name, code UNIQUE,
  description NULL, status, created_at, updated_at, deleted_at

shifts
  id, name, code UNIQUE,
  start_time TIME, end_time TIME,
  crosses_midnight TINYINT(1) INDEX   -- derived: end <= start
  break_duration SMALLINT (minutes), grace_period SMALLINT (minutes),
  minimum_working_hours DECIMAL(4,2), overtime_threshold DECIMAL(4,2),
  status, created_at, updated_at, deleted_at

projects
  id, name, code UNIQUE, client NULL, description NULL, location NULL,
  project_manager_id FK→employees NULL,   -- FK added by deferred migration
  start_date DATE NULL, end_date DATE NULL, status DEFAULT 'planned',
  created_at, updated_at, deleted_at

employees
  id, user_id FK→users UNIQUE NULL, employee_code UNIQUE,
  first_name, middle_name NULL, last_name, photo_path NULL,
  email UNIQUE, phone NULL,
  date_of_birth DATE NULL, nationality NULL, address NULL,
  emergency_contact_name / _phone / _relation NULL,
  joining_date DATE,
  department_id FK NULL, designation_id FK NULL,
  employment_type VARCHAR(20) DEFAULT 'permanent',
  reporting_manager_id FK→employees NULL,
  primary_project_id FK→projects NULL,
  primary_site_id FK→sites NULL,         -- FK added by deferred migration
  salary DECIMAL(12,2) NULL,
  employment_status VARCHAR(20) DEFAULT 'active',
  created_at, updated_at, deleted_at

sites
  id, project_id FK→projects RESTRICT, name, code UNIQUE, address NULL,
  latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL,
  geofence_radius DECIMAL(8,2) NULL,      -- metres, per site
  site_manager_id FK NULL, site_supervisor_id FK NULL,
  working_hours_setting_id FK→settings NULL,
  shift_id FK→shifts NULL,
  status DEFAULT 'active', created_at, updated_at, deleted_at

employee_site_assignments               -- append-only, NO deleted_at
  id, employee_id FK RESTRICT, project_id FK RESTRICT, site_id FK RESTRICT,
  assignment_type VARCHAR(20) DEFAULT 'primary',   -- primary|temporary|additional
  start_date DATE, end_date DATE NULL,
  status VARCHAR(20) DEFAULT 'active',             -- active|ended|cancelled
  created_by FK→users NULL, created_at, updated_at
```

### Status vocabularies

These are **fixed, app-enforced** enumerations (PHP constants on each model),
not MySQL `ENUM`, so adding a value never needs a schema change and the tests
assert the exact vocabulary:

| Table | Column | Values |
|---|---|---|
| `employees` | `employment_status` | `active`, `inactive`, `resigned`, `terminated`, `on_leave` |
| `employees` | `employment_type` | `permanent`, `contract`, `probation`, `internship`, `part_time` |
| `projects` | `status` | `planned`, `active`, `on_hold`, `completed`, `cancelled` |
| `employee_site_assignments` | `assignment_type` | `primary`, `temporary`, `additional` |
| `employee_site_assignments` | `status` | `active`, `ended`, `cancelled` |
| `departments`, `designations`, `shifts`, `sites` | `status` | `active`, `inactive` |
| `attendances` | `status` | `present`, `late`, `incomplete`, `missing_checkout`, `manually_adjusted` — written only by `AttendanceStatusCalculator`; the last value has no writer yet |
| `attendances` | `source` | `online`, `offline`, `manual` — `manual` is server-only; a client sending it is ignored |
| `site_visits` | `status` | `open`, `closed` |

### Numeric type choices

| Column | Type | Why |
|---|---|---|
| `latitude` / `longitude` | `DECIMAL(10,7)` | ~1.1 cm precision. `FLOAT` is binary and rounds unpredictably at a geofence boundary. |
| `geofence_radius` | `DECIMAL(8,2)` | Fractional metres allowed; still bounded. |
| `check_in_accuracy`, `check_out_accuracy`, `start_accuracy`, `end_accuracy` | `DECIMAL(8,2)` | Metres reported by the device, and the ceiling the geofence compares against — a whole metre is too coarse for a 100 m radius. |
| `check_in_distance`, `check_out_distance`, `start_distance`, `end_distance` | `DECIMAL(10,2)` | Server-computed metres. Never read from a request. |
| `salary` | `DECIMAL(12,2)` | Money — never `FLOAT`. |
| `minimum_working_hours`, `overtime_threshold` | `DECIMAL(4,2)` | Fractional hours without float drift. |
| `break_duration`, `grace_period` | `SMALLINT` | Whole minutes; bounded to 65 535. |
| `start_time`, `end_time` | `TIME` | Clock times, not instants — no timezone/DST drift. |
| `check_in_at`, `check_out_at`, `scheduled_start_at`, `scheduled_end_at`, `started_at`, `ended_at` | `DATETIME` | Instants on the server clock. **Not** `TIMESTAMP`: MariaDB 10.4 with `explicit_defaults_for_timestamp=0` would give the first `NOT NULL timestamp` column an implicit `ON UPDATE CURRENT_TIMESTAMP`. |

---

## 10. Not Yet Created (Future Phases)

`audit_logs`, `notifications`, `notification_preferences`, `employee_documents`,
`employee_onboarding`, `trainings`, `employee_trainings`, `assets`,
`asset_assignments`, `device_tokens`.

**Phase 8 built seven of these** (`payrolls`, `payroll_items`, `allowances`,
`payroll_adjustments`, `loans`, `loan_installments`,
`salary_certificate_requests`) and deliberately did **not** build a
`salary_slips` table: a slip is a `payrolls` row rendered to PDF on demand, so
there is no stored document to expire, cache or leak.

**Phase 9 built `expenses` and `expense_receipts`** — the two that had been
sitting on this list since Phase 2 — together with `expense_categories`,
which was designed alongside them and seeded with six rows. All three are
documented in **§2.10**. Everything still named above remains design only.

> **Deliberately absent:** `leave_documents` — a medical certificate is a file
> on the private disk with its metadata on `leave_requests` (§2.6).
>
> Phase 6 added no payroll table either, only the two facts a run needs:
> `overtime_requests.payroll_eligible` and `leave_requests.lop_days`. Phase 8
> then read both and wrote nothing back — payroll is a **reader** of
> attendance, overtime and leave, never a writer into them.
>
> Phase 7 added no inventory and no asset module either. What a site *used*
> on a day lives in `daily_site_report_materials` and
> `daily_site_report_equipment` as facts about one report, with no stock
> level, no valuation, no asset tag and no maintenance schedule — so a real
> stock or asset module can be built later without inheriting numbers the
> daily reports have already grown to depend on.

> **Current state:** only `hrms_laravel` (development) and `hrms_testing` (automated
> tests) are used. Other databases on this machine belong to previous, unrelated
> projects and are left untouched. See §"Development vs testing database".
