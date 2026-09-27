# Database Design

> **Status:** Phase 5 — core schema, the auth addition, the first slice
> **using** it, and now GPS attendance and site movement. Laravel's base
> tables plus 10 Phase 2 migrations, the Phase 3 `users.status` column and
> the two Phase 5 migrations all exist: `settings`, `departments`,
> `designations`, `shifts`, `projects`, `employees`, `sites`,
> `employee_site_assignments`, **`attendances`**, **`site_visits`** and the
> `spatie/laravel-permission` RBAC tables. Tables for later phases
> (`leave_requests`, `payrolls`, `site_activity_reports`, …) are **design
> only** and have not been created. `hrms_testing` mirrors this schema for
> the test suite.

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
| `site_activity_reports` | Work performed, progress %, materials, manpower, equipment, issues, safety | ⬜ Later |
| `site_report_photos` | Multiple photos per report | ⬜ Later |
| `daily_site_reports` | Supervisor's daily report (workforce, planned/completed, delays) | ⬜ Later |

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

### 2.5 Time

| Table | Purpose |
|---|---|
| `timesheets` | Daily/period records: shift, check-in/out, working hours, break, overtime, status, approval |
| `overtime_requests` | Requested vs approved hours, reason, multi-step approval, approved_by |

**Rule:** only overtime with status `approved` enters payroll.

---

### 2.6 Leave

| Table | Purpose |
|---|---|
| `leave_types` | Configurable: entitlement, carry-forward, max days, requires document, document deadline, approval workflow |
| `leave_balances` | Per employee × leave type × year: entitlement, used, pending, carry-forward, remaining |
| `leave_requests` | Request with dates, reason, status (Draft/Pending/Approved/Rejected/Cancelled/LOP), approval route snapshot |
| `leave_documents` | Medical certificate and other attachments |

**Sick leave rule (configurable, default 3 days):**

```
Employee submits sick leave
        ↓
Scheduler job runs daily (server-side, not the app)
        ↓
Certificate not uploaded within configured deadline?
        ↓
1. Mark leave as LOP
2. Record reason
3. Create audit log
4. Notify employee
5. Notify HR
```

---

### 2.7 Payroll & Finance

| Table | Purpose |
|---|---|
| `payrolls` | Monthly run per employee: basic, allowances, overtime, bonus, deductions, LOP, gross, net, status |
| `payroll_items` | Line-item detail for each payroll record |
| `salary_slips` | Generated PDF reference, period, access control |
| `salary_certificate_requests` | Request date, status, approved_by, generated document |
| `loans` | Loan/advance amount, installments, start date, outstanding balance, status |
| `loan_installments` | Per-installment schedule and payment status |
| `expenses` | Category, date, project, site, amount, description, status (workflow) |
| `expense_receipts` | Receipt files (private storage) |

**Payroll reads only approved data** — approved attendance, approved overtime, leave
marked LOP, approved loans, approved expenses.

---

### 2.8 Notifications

| Table | Purpose |
|---|---|
| `notifications` | Laravel's table: database inbox, cross-device persistence |
| `user_notifications` / `device_tokens` | FCM device tokens per user (multi-device) |
| `notification_preferences` | Which events a user wants, per channel |

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

**Circular-reference note:** `employees.primary_project_id → projects` and
`projects.project_manager_id → employees` are mutually referential, as are
`employees.primary_site_id → sites` and `sites.site_manager_id → employees`. Both
pairs are created across two migrations (see §6) to avoid a circular dependency
at schema-creation time. They are *not* an architectural loop — one side is a
"current placement" pointer, the other is a staffed-role pointer.

**Designed but not yet created (Phases 6–12):**

```
employees ──*── site_activity_reports *── sites
           ├──*── timesheets
           ├──*── overtime_requests
           ├──*── leave_requests *── leave_types
           │                        └── leave_balances
           ├──*── payrolls *── payroll_items
           ├──*── salary_slips
           ├──*── salary_certificate_requests
           ├──*── loans *── loan_installments
           ├──*── expenses *── expense_receipts
           ├──*── employee_documents
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
| Leave approval queue | `(status, request_date)` |
| Document expiry | `(expiry_date)` |
| Payroll period | `(period_month, employee_id)` |

**N+1 prevention:** all list endpoints eager-load their relations via `with()`, and are
verified with `DB::enableQueryLog()` during testing.

---

## 5. Seed Data (Phase 2)

All counts below are **live and verified** against `hrms_laravel` after
`php artisan migrate:fresh --seed`.

| Seeded | Count | Seeder |
|---|---|---|
| Roles | 10 | `RoleSeeder` |
| Permissions | 40 | `PermissionSeeder` |
| Role → permission grants | 168 | `RolePermissionSeeder` |
| Settings | 12 | `SettingSeeder` |
| Shifts | 4 — General, Morning, Evening, Night | `DevelopmentDataSeeder` |
| Departments | 4 *(development sample)* | `DevelopmentDataSeeder` |
| Designations | 8 *(development sample)* | `DevelopmentDataSeeder` |

Phase 4 added exactly one permission, `employees.salary.view`, granted to
HR Admin, Payroll Admin and Finance (Super Admin holds it through `*`).
Phase 5 added no permission but granted the existing `attendance.view` to
the `Employee` role, taking the total to 168 — without it an employee could
not read back the day they themselves recorded. Re-running the two seeders
brings an older dev database up to date without touching anything else —
they are `firstOrCreate` / `syncPermissions` only.

Not yet seeded (later phases): leave types, demo projects/sites.

> ⚠️ `DevelopmentDataSeeder` contains **sample structure only**. It creates no
> employees, users, salaries or assignments. Every row it writes is labelled
> *"Development sample data."* in its `description`.
>
> All five seeders are **idempotent** (`firstOrCreate` / `updateOrCreate`), so
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

**25 tables** total in `hrms_laravel`, across **19 migrations** (3 framework,
1 Sanctum, 10 Phase 2, 1 Phase 3, 2 Phase 5).

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

**Soft deletes** (`deleted_at`) are used on master data where recoverability
matters: `departments`, `designations`, `employees`, `projects`, `sites`, `shifts`.
`employee_site_assignments` and `settings` are **not** soft-deleted — assignments
are immutable history, settings are a small config table.

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

Composite indexes were chosen for the two filters that appear together most
often in HR reports: *department × status* and *site × start date*. The two
Phase 5 tables add the two that appear together in every attendance query:
*employee × date* (which is also the uniqueness rule) and *site × date*.

**N+1 prevention:** all list endpoints eager-load their relations via `with()`, and are
verified with `DB::enableQueryLog()` during testing.

---

## 9. Phase 2 Tables — Full Column Reference

> The Phase 5 tables (`attendances`, `site_visits`) are documented in full in
> §2.4 above, alongside their design reasons.

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
`asset_assignments`, `holidays`, `site_activity_reports`, `site_report_photos`,
`daily_site_reports`, `timesheets`, `overtime_requests`, `leave_types`,
`leave_balances`, `leave_requests`, `leave_documents`, `payrolls`,
`payroll_items`, `salary_slips`, `salary_certificate_requests`, `loans`,
`loan_installments`, `expenses`, `expense_receipts`, `device_tokens`.

> **Current state:** only `hrms_laravel` (development) and `hrms_testing` (automated
> tests) are used. Other databases on this machine belong to previous, unrelated
> projects and are left untouched. See §"Development vs testing database".
