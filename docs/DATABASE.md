# Database Design

> **Status:** Phase 1 — Foundation. The HRMS business tables defined below have **not**
> been created yet — only Laravel's base tables (`users`, `cache`, `jobs`, `sessions`,
> `password_reset_tokens`, `personal_access_tokens`) currently exist.
> This document will be updated with the actual migration list as Phase 2 is implemented.

**DBMS:** MariaDB 10.4.28 (XAMPP)
**Charset:** `utf8mb4` / collation `utf8mb4_unicode_ci`

### Current connection settings

| Setting | Value |
|---|---|
| Host | `127.0.0.1` |
| Port | `3306` |
| Database | `hrms_laravel` |
| Username | `hrms_app` — scoped to `hrms_laravel` only, never `root` |
| Password | `DB_PASSWORD` in `backend/.env` (git-ignored, never committed) |

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
                              ├── 1 ──── 1 users
                              │
                              ├── * ──── 1 reporting_manager (self FK)
                              │
                              └── * ──── 1 photo (private storage path)
```

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
  geofence_radius   INT (metres)    -- configurable per site by HR/Admin
```

---

### 2.4 Attendance & Movement

| Table | Purpose |
|---|---|
| `attendances` | **One row per employee per work day** — check-in/out, sites, GPS, accuracy, distance, selfie, status, source (online/offline), device info, `client_uuid` (idempotency key) |
| `site_visits` | Movement between sites: start/end time, start/end GPS, purpose, remarks, status |
| `site_activity_reports` | Work performed, progress %, materials, manpower, equipment, issues, safety |
| `site_report_photos` | Multiple photos per report |
| `daily_site_reports` | Supervisor's daily report (workforce, planned/completed, delays) |

#### `attendances` shape

```
attendances
  id
  employee_id            FK
  work_date              DATE
  check_in_at            DATETIME
  check_in_site_id       FK → sites
  check_in_project_id    FK → projects
  check_in_lat, check_in_lng
  check_in_accuracy      DECIMAL  (GPS accuracy in metres)
  check_in_distance      DECIMAL  (metres from site centre — server computed)
  check_in_selfie_path   VARCHAR  (private storage)
  check_out_at           DATETIME, nullable
  check_out_site_id      FK, nullable      ← may differ from check-in site
  check_out_lat, check_out_lng, check_out_accuracy, check_out_distance
  check_out_selfie_path  VARCHAR, nullable
  status                 PRESENT / LATE / ABSENT / ON_LEAVE / LOP / OVERRIDE / MISSING_CHECKOUT
  source                 ONLINE / OFFLINE_SYNC / HR_OVERRIDE
  client_uuid            CHAR(36) UNIQUE   ← duplicate prevention
  device_info            JSON, nullable
  overridden_by          FK users, nullable
  override_reason        TEXT, nullable
  UNIQUE (employee_id, work_date)
```

**`UNIQUE (employee_id, work_date)`** is the primary defence against duplicate
attendance. **`client_uuid` UNIQUE** is the defence against duplicate *offline sync*.

**Why attendance_locations is not a separate table (for now):** every GPS capture is
already stored on its own row in `attendances` (check-in and check-out columns) and in
`site_visits`. A third location table would duplicate that data. It will be introduced
only if a requirement for multiple location pings per punch emerges.

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

**Sick leave rule (configurable, default 2 days):**

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

```
users ── 1:1 ── employees ──*── departments
                        │              │
                        │              └── designations
                        │
                        ├──*── employee_site_assignments *── projects *── sites
                        │                                              │
                        │                                              └── shifts
                        │
                        ├──*── attendances *── sites
                        ├──*── site_visits  *── sites
                        ├──*── site_activity_reports *── sites
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

---

## 4. Indexing Strategy

| Pattern | Index |
|---|---|
| Every FK | Single-column index |
| Attendance by day | `(work_date, status)` |
| Attendance by employee + date | `UNIQUE (employee_id, work_date)` |
| Offline sync idempotency | `UNIQUE (client_uuid)` |
| Site lookup | `(project_id, status)` |
| Open assignment lookup | `(employee_id, status, end_date)` |
| Leave approval queue | `(status, request_date)` |
| Document expiry | `(expiry_date)` |
| Payroll period | `(period_month, employee_id)` |

**N+1 prevention:** all list endpoints eager-load their relations via `with()`, and are
verified with `DB::enableQueryLog()` during testing.

---

## 5. Seed Data (Phase 2)

| Seeded | Count |
|---|---|
| Roles | 10 |
| Permissions | ~40 (see §Permissions in ARCHITECTURE) |
| Departments / Designations | Sample set |
| Settings | All configurable defaults |
| Leave types | Annual, Sick, Emergency, Unpaid, Other |
| Shifts | General, Morning, Evening, Night |
| Demo projects + sites (with real coordinates) | 2–3 |

---

## 6. Migration History

| Phase | Migrations | Status |
|---|---|---|
| 1 | *(none — foundation only)* | ⬜ |
| 2 | Core schema | ⬜ |
| 3 | Auth + Sanctum | ⬜ |
| 4 | Employees / Projects / Sites | ⬜ |
| 5 | Attendance | ⬜ |

> **Current state:** the database has **not** been created yet. Existing databases on this
> machine belong to a previous, unrelated project and are left untouched.
