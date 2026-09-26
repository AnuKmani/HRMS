# Testing

> **Status:** Phase 3 — auth covered end to end. Backend **120 passed (517
> assertions)**, Flutter **43 passed**. This document defines the strategy for
> the modules still to come (Phases 4–12).

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

### 3.1 Authentication ✅ (Phase 3 — `AuthenticationTest`, `PasswordResetTest`, `ApiErrorHandlingTest`)

| Test | Expectation | Covered by |
|---|---|---|
| Valid credentials | `200`, returns token + user + permissions | `AuthenticationTest::test_login_returns_a_token_and_the_authenticated_user` |
| Response leaks nothing | No password hash in the response; only a hash stored, never the plain token | `…never_contains_a_password_hash`, `…plain_text_token_is_never_stored` |
| Invalid password | `401` | `…identical_answer_for_unknown_email_and_wrong_password` |
| Unknown email | `401` (identical body — no account-existence oracle) | same test |
| Missing fields | `422` | `…rejects_missing_credentials_with_a_422` |
| Deactivated account | `401` **with** the correct password | `…deactivated_account_cannot_sign_in_even_with_correct_password` |
| Device naming | Default when omitted; same device replaces its previous token | `…defaults_the_device_name…`, `…replaces_the_previous_token_for_the_same_device` |
| `GET /auth/me` | `200` with roles + permissions; works with no employee record | `…me_returns_the_authenticated_user`, `…me_works_without_an_employee_record` |
| Missing / garbage / revoked token | `401`, same envelope | `…me_requires_a_bearer_token`, `…me_rejects_a_garbage_token`, `…me_rejects_a_token_that_was_signed_out` |
| Logout | Token revoked, next request → `401` | `…me_rejects_a_token_that_was_signed_out` |
| Change password wrong current | `422` with `current_password` field error | `…change_password_rejects_an_incorrect_current_password` |
| Change password weak/unconfirmed | `422` | `…change_password_rejects_a_weak_or_unconfirmed_password` |
| Change password effect | Persists new hash, signs every **other** device out | `…persists_the_new_one`, `…signs_every_other_device_out` |
| Sessions list | Devices + timestamps, **no token hash** | `…sessions_lists_devices_without_exposing_the_token_hash` |
| Revoke a session | That device is signed out | `…revoking_a_session_signs_that_device_out` |
| Revoke someone else's session | `404` (not `403` — a 403 would confirm it exists) | `…a_session_belonging_to_another_account_cannot_be_revoked` |
| Rate limiting | 6th login attempt in a minute → `429`; limits read from config | `ApiErrorHandlingTest::test_repeated_login_attempts_return_a_429_envelope`, `…throttle_is_configured_rather_than_hard_coded` |
| Forgot password (disabled) | `501` with an honest message | `PasswordResetTest::test_forgot_password_reports_that_it_is_not_available_yet` |
| Forgot password address oracle | Identical answer for known and unknown | `…answers_identically_for_known_and_unknown_addresses` |
| Reset password | Swaps password, kills every session | `…reset_password_swaps_the_password_and_kills_every_session` |
| Reset bad token | Rejected without touching the account | `…reset_password_rejects_a_bad_token_without_touching_the_account` |
| Validation before feature flag | `422` still wins over `501` | `…validation_still_runs_before_the_enabled_check` |

### 3.1a Error envelope (shared) ✅ `ApiErrorHandlingTest`

`401`, `403`, `404` (unknown route *and* missing record), `405`, `422`, `429`,
`500` — each asserted to be a well-formed envelope, and the `500` case asserted
to contain **no** SQL, stack trace, model class or internal path.

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

### 5.1 Unit ✅ (Phase 3)

| Target | Tests | File |
|---|---|---|
| `ApiException` envelope parsing | 401/422 read as the server wrote them; `Retry-After` parsed; timeout **never** blames the user; a non-envelope body is never shown as prose | `test/core/network/api_exception_test.dart` |
| `AuthController` state machine | initial status, restore outcomes (accepted / rejected / unreachable / empty), login success + failure paths, 422 field map, throttle wait, `dismissFeedback`, logout (server ok / unreachable / no session), session-rejected broadcast | `test/features/auth/auth_controller_test.dart` |
| Validators | empty form says what is missing; the typed credentials are what reaches the API | `test/features/auth/login_screen_test.dart` |
| Models | Auth models parse exactly the fields the API returns (`AuthUser`, `EmployeeBrief`) | exercised through the repository/controller tests above |

**Two non-obvious guarantees worth keeping under test:**

- *Restore asks the server **once**.* `AuthController` may be read by the
  router, the splash screen and the session-rejected listener in the same
  frame; `build()` must not fan out a request per reader.
- *A rejection is announced once.* If the server drops a token, both the Dio
  interceptor and the controller can notice. The sign-in form's own message
  must survive.

### 5.2 Widget ✅ (Phase 3 — Login)

| Screen | States verified |
|---|---|
| Login | empty/invalid submit, success → home, 401 as **one banner with no field marked**, 422 with `forceErrorText` under the named field, 429 with countdown, password masked until asked for, editing clears a superseded message |
| Splash | holds `/` while restoring — `advance()` pumps instead of `pumpAndSettle()`, because the restoring spinner is intentionally endless |
| Attendance / Leave list / all screens | ⬜ Phase 4 onward |

> **Don't read `TextFormField.obscureText`** — it is not public. Read the
> widget's own `TextField.obscureText` field instead.
>
> **Avoid `pumpAndSettle`** anywhere the splash spinner is visible: it never
> settles. Eight × 100 ms pumps (`advance()`) is enough for any transition in
> this feature.

### 5.3 Behavioural ✅ (Phase 3 — `test/app_test.dart`)

| Behaviour | Expectation |
|---|---|
| Nothing stored | lands on the sign-in form |
| Stored token, server accepts | straight to `/home` |
| Stored token, server says `401` | discards it, back to `/login` |
| Server unreachable during restore | **keeps** the token — an offline launch must not sign someone out |
| Signed in, visiting `/` or `/login` | redirected to `/home` |
| Not signed in, visiting `/home` | redirected to `/login` — and back again if it tries to leave |
| Submitting the form | navigates to `/home` with **no** explicit `Navigator` call from the screen |
| Signing out | returns to the form |
| Token revoked elsewhere (401 mid-session) | returns the user to the form |
| 403 response | shows "not authorized", **does not log out** | ⬜ Phase 4 |
| Network loss / offline queue | offline message, action queued | ⬜ Phase 6 |
| Permission hidden | HR control absent for an Employee user | ⬜ Phase 4 |

### 5.4 What NOT to test in Flutter

Business rules (geofence acceptance, balance calculation, payroll maths) are tested in
Laravel. Flutter tests confirm **rendering and state handling** only.

**Doubles, not sockets.** Every Flutter test runs against
`test/support/fakes.dart` — `InMemoryTokenStore`, `FixedDeviceIdentity`,
`FakeAuthRepository`. `flutter_secure_storage` talks to the platform keychain
over a method channel, and `ApiAuthRepository` would need a live server;
neither has anything useful to say inside a widget test.

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

flutter test                          # all tests
flutter test test/features/auth       # one folder
flutter test test/app_test.dart       # one file
flutter test --coverage               # coverage report at coverage/lcov.info
```

> There is no device or backend behind any of these. The suites run against
> fakes in `test/support/fakes.dart`, so they stay green while a server is
> stopped — which is exactly when a regression shows up.

### Static analysis (run before every commit)
```bash
cd backend
./vendor/bin/pint --test          # Laravel code style
composer validate                 # composer.json integrity

cd mobile
flutter analyze                   # must report no issues
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
| Development/testing database split (`hrms_laravel` vs `hrms_testing`) | ✅ Phase 2 safety cleanup |
| Backend test suite | ✅ **120 passed (517 assertions)** — 75 from Phase 2 + 45 Phase 3 |
| Flutter test suite | ✅ **43 passed** |
| `flutter analyze` / `pint --test` / `composer validate` clean | ✅ |
| CI pipeline running tests on every commit | ⬜ |
| Coverage measurement (needs xdebug/pcov) | ⬜ |
