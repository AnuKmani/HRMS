# Testing

> **Status:** Phase 1 — Foundation. No tests exist yet. This document defines the test
> strategy, priorities and how to run each suite as phases are delivered.

---

## 1. Test Pyramid

```
            ┌──────────────┐
            │   Feature     │   Full HTTP request through middleware,
            │   (API) tests │   auth and DB — a handful per module
            ├──────────────┤
            │   Unit tests  │   Services, calculations, geofence math,
            │   (bulk)      │   leave balance, payroll, policies
            ├──────────────┤
            │   Widget/UI   │   Flutter widget states: loading, error,
            │   tests       │   empty, data
            └──────────────┘
```

**Where the value is:** the critical business rules are in Laravel Services, so that is
where most tests belong. UI tests confirm rendering, not business logic.

---

## 2. Test Environment

| Concern | Approach |
|---|---|
| Database | **`hrms_testing`** — separate schema, reset by `RefreshDatabase` |
| Filesystem | `storage/framework/testing` / fake disk |
| Network | HTTP calls faked in Flutter tests |
| FCM | Fake notification channel |
| Queue | `QUEUE_CONNECTION=sync` in tests, or `Bus::fake()` |
| External API | Http fake — never hit a real service |

`phpunit.xml` points at a **test** database. Tests must never touch real data.

### The two databases

| Purpose | Database | Configured in |
|---|---|---|
| Development | `hrms_laravel` | `backend/.env` → `DB_DATABASE` |
| Testing | `hrms_testing` | `backend/phpunit.xml` → `<env name="DB_DATABASE">` |

`RefreshDatabase` runs `migrate:fresh`, which **drops every table**. Pointing that at
`hrms_laravel` would destroy local data on every `php artisan test`, so only
`DB_DATABASE` is overridden in `phpunit.xml` — host, port and credentials still come
from `.env`, keeping secrets out of git.

```bash
php artisan test      # hrms_testing only — hrms_laravel untouched
```

`hrms_testing` is disposable and rebuilt from migrations each run. See
[`DATABASE.md`](DATABASE.md) §"Development vs testing database".

---

## 3. Backend Test Matrix

### 3.1 Authentication

| Test | Expectation |
|---|---|
| Valid credentials | `200`, returns token + user + permissions |
| Invalid password | `401` |
| Unknown email | `401` (does not reveal whether the user exists) |
| Missing fields | `422` |
| Logout | token revoked, subsequent request → `401` |
| Expired/invalid token | `401` |
| Forgot password | reset token issued, token single-use |
| Rate limiting | 7th login attempt in a minute → `429` |
| Change password wrong current | `422` |

### 3.2 Authorization (highest priority)

| Test | Expectation |
|---|---|
| Employee hits an HR endpoint | `403` |
| Employee reads another employee's salary slip | `403` |
| Employee reads another employee's attendance | `403` |
| Employee deletes an employee | `403` |
| HR Executive creates an employee | `201` (has permission) |
| HR Executive processes payroll | `403` (no permission) |
| Site Supervisor approves leave | `403` |
| Project Manager views own project sites | `200` |
| Unauthenticated request | `401` |

> **Rule:** hiding a button is not authorization. Every denied action must return `403`
> from the API regardless of what the UI shows.

### 3.3 Attendance (core module)

| # | Scenario | Expectation |
|---|---|---|
| 1 | Check-in **inside** geofence | `201`, attendance created, `distance` computed |
| 2 | Check-in **outside** geofence | **`422`, no attendance record created** |
| 3 | Exactly at radius boundary | `201` (inclusive) |
| 4 | Radius + 1 metre | `422` |
| 5 | Duplicate check-in same day | `409` / `422` — no second row |
| 6 | Check-in without selfie | `422` |
| 7 | Check-in with wrong file type | `422` |
| 8 | Check-in with oversized image | `422` |
| 9 | Check-out | `200`, working hours calculated |
| 10 | Check-out without check-in | `422` |
| 11 | Check-out at a **different** site | `200`, both sites recorded |
| 12 | Late arrival (after grace period) | status = `late` |
| 13 | Early departure | flagged per configuration |
| 14 | Missing check-out | flagged by scheduler |
| 15 | Offline sync, valid GPS | `200`, record created once |
| 16 | **Same `client_uuid` replayed** | returns original result, **no duplicate row** |
| 17 | Offline sync, GPS outside geofence | rejected with reason |
| 18 | Offline sync, tampered timestamp outside tolerance | flagged for HR review |
| 19 | HR override without reason | `422` |
| 20 | HR override with reason | `200` + **audit log written** |

**The single most important test:**

```
Employee outside site geofence  →  check-in rejected  →  no attendance record
```

### 3.4 Geofence unit tests

| Input | Expected |
|---|---|
| Same coordinates as site | `0.0` m → inside |
| Known coordinate pair | distance matches reference value (±0.5%) |
| Opposite side of the equator | ~20,000 km → outside |
| Missing latitude | validation error, not an exception |
| Zero coordinates (GPS failure) | rejected, not treated as "at the site" |

### 3.5 Leave

| Test | Expectation |
|---|---|
| Create leave within balance | `201` |
| Create leave exceeding balance | `422` |
| Overlapping leave request | `422` |
| Approve by authorized user | `200`, status `approved`, balance decremented |
| Approve own request (self-approval) | `403` where workflow forbids it |
| Reject requires reason | `422` without reason |
| Balance: entitlement + carry forward | correct `remaining` |
| Pending requests counted separately | `pending ≠ used` |
| **Sick leave without certificate by deadline** | **converted to LOP** |
| LOP conversion writes audit log | audit row exists |
| LOP conversion notifies employee + HR | 2 notifications queued |
| Leave on a holiday | not deducted from balance |

### 3.6 Overtime

| Test | Expectation |
|---|---|
| Request created | `201`, status `pending` |
| Approved → included in payroll | payroll line present |
| Rejected → excluded from payroll | no payroll line |
| Approve beyond requested hours | `422` |
| Employee approves own OT | `403` |

### 3.7 Payroll

| Test | Expectation |
|---|---|
| Basic + allowances | gross correct |
| Overtime added (approved only) | included |
| LOP days deducted | deducted correctly |
| Loan installment deducted | outstanding balance decremented |
| Tax/deduction applied | correct |
| `net = gross − deductions` | **asserted exactly** |
| Running payroll twice for same month | prevented |
| Employee views another's payslip | `403` |
| Non-payroll user views payroll list | `403` |

Use **exact decimal assertions** — money must never be compared as floats.

### 3.8 Documents & Files

| Test | Expectation |
|---|---|
| Upload valid document | `201`, stored in private disk |
| Upload `.php` renamed to `.jpg` | `422` (MIME sniffing) |
| Upload oversized file | `422` |
| Direct URL to a private file | `403` / `404` |
| Signed URL valid within expiry | `200` |
| Signed URL after expiry | `403` |
| Another employee's document | `403` |
| Expiring document within lead time | reminder job queued |
| Expired document | status updated by scheduler |

### 3.9 Sites & Assignment

| Test | Expectation |
|---|---|
| Assign employee to a new site | new assignment row, **previous row untouched** |
| End an assignment | `end_date` set, history preserved |
| Change geofence radius | takes effect on next check-in |
| Employee with no site assignment | check-in rejected with clear message |
| Radius is read from DB, not hard-coded | changing the value changes the outcome |

---

## 4. Scheduler / Job Tests

| Job | Test |
|---|---|
| Sick certificate deadline | `travelTo()` past deadline → run job → leave becomes LOP |
| LOP conversion | Audit log + 2 notifications created |
| Document expiry reminder | Expiring doc → notification queued |
| Training expiry reminder | Expiring certificate → notification queued |
| Missing check-out detection | Runs → attendance flagged |
| Attendance reminder | Employee with no check-in → notification queued |

Use `Queue::fake()` / `Notification::fake()` and `Carbon::setTestNow()`.

---

## 5. Flutter Test Matrix

### 5.1 Unit

| Target | Tests |
|---|---|
| Models | `fromJson` / `toJson` round-trip, null handling |
| Repository | offline → local, online → network, sync marks status |
| Providers | state transitions loading → data / error |
| Validators | form rules |
| Haversine util | matches backend result |

### 5.2 Widget

| Screen | States to verify |
|---|---|
| Login | loading, success, validation errors, 401, 429, network error |
| Attendance | empty, loading, checked-in, checked-out, **offline pending**, error |
| Leave list | empty, loaded, pagination, error |
| All screens | loading / error / empty / data all render |

### 5.3 Behavioural

| Behaviour | Expectation |
|---|---|
| 401 response | navigates to login, token cleared |
| 403 response | shows "not authorized", **does not log out** |
| 422 response | messages appear on the correct fields |
| Network loss | offline message, action queued |
| Sync | badge changes pending → synced |
| Permission hidden | HR control absent for an Employee user |

### 5.4 What NOT to test in Flutter

Business rules (geofence acceptance, balance calculation, payroll maths) are tested in
Laravel. Flutter tests confirm **rendering and state handling** only.

---

## 6. Critical Scenarios (must never regress)

### Scenario A — Outside geofence
```
Employee opens attendance
  → gets GPS outside the site radius
  → taps CHECK IN
  → Laravel rejects (422)
  → NO attendance record created
  → clear message shown
  → audit log entry written
```

### Scenario B — Missing medical certificate
```
Employee submits sick leave
  → does not upload certificate
  → configured deadline (default 2 days) passes
  → scheduler runs server-side
  → leave converted to LOP
  → reason recorded
  → audit log created
  → employee notified
  → HR notified
  → runs even if the app is closed
```

### Scenario C — Offline duplicate prevention
```
Employee checks in with no signal
  → record queued locally with client_uuid
  → connectivity returns
  → upload succeeds → marked synced
  → upload RETRIED (timeout / retry)
  → server sees the same client_uuid
  → returns the ORIGINAL result
  → still exactly ONE attendance row
```

### Scenario D — Site assignment history
```
Employee assigned to Site A (Jan 05)
  → reassigned to Site B (Mar 01)
  → Site A row still exists with end_date = Feb 28
  → history report shows both
  → nothing was overwritten
```

---

## 7. Running Tests

### Backend
```bash
cd backend

php artisan test                      # everything
php artisan test --filter=Attendance   # one module
php artisan test --testsuite=Unit      # unit only
php artisan test --coverage            # with coverage (needs xdebug/pcov)

# or directly
vendor/bin/phpunit --testsuite=Feature
vendor/bin/phpunit --filter=GeofenceTest
```

### Frontend
```bash
cd mobile

flutter test                    # all tests
flutter test test/attendance    # one folder
flutter test --coverage         # coverage report at coverage/lcov.info
```

### Static analysis (run before every commit)
```bash
cd backend
./vendor/bin/pint --test          # Laravel code style
./vendor/bin/phpstan analyse      # if installed

cd mobile
flutter analyze                   # must report no issues
dart format --set-exit-if-changed lib
```

---

## 8. Coverage Targets

| Area | Target |
|---|---|
| Services (geofence, leave, payroll) | **≥ 90%** |
| Policies / authorization | **≥ 90%** |
| Controllers | ≥ 70% |
| Flutter models + repositories | ≥ 80% |
| Flutter widgets | smoke tests on every screen |
| Overall backend | ≥ 75% |

Coverage is a floor, not a goal — the four critical scenarios in §6 matter more than
a number.

---

## 9. Definition of Done (per phase)

A phase is complete only when:

- [ ] New/changed endpoints have tests
- [ ] Authorization tested for each new endpoint (`403` for unauthorized roles)
- [ ] Validation failure paths tested (`422`)
- [ ] Critical scenarios still pass
- [ ] `php artisan test` green
- [ ] `flutter analyze` clean
- [ ] Code style check passes
- [ ] No secrets introduced into the repository

---

## 10. Status

| Item | Status |
|---|---|
| Test strategy (this document) | ✅ Written |
| Backend test suite | ⬜ Phase 3 onward |
| Flutter test suite | ⬜ Phase 1b onward (smoke), Phase 3 onward (real) |
| CI pipeline running tests on every commit | ⬜ |
