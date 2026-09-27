# Testing

> **Status:** Phase 6 — leave, certificates, LOP, holidays, timesheets and
> overtime are covered end to end on top of Phases 1–5. Backend
> **383 passed (2017 assertions)**, Flutter **215 passed**.
> This document defines the strategy for the modules still to come (Phases 7–12).

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
| Employee reads another employee's attendance | `403` | ✅ Phase 5 |
| Employee deletes an employee | `403` |
| HR Executive creates an employee | `201` (has permission) |
| HR Executive processes payroll | `403` (no permission) |
| Site Supervisor approves leave | `403` |
| Project Manager views own project sites | `200` |
| Unauthenticated request | `401` |

> **Rule:** hiding a button is not authorization. Every denied action must return `403`
> from the API regardless of what the UI shows.

### 3.2a Organisation modules ✅ (Phase 4)

Ten feature files, one per resource plus the two relationship suites:

| File | What it proves |
|---|---|
| `DepartmentApiTest` | read vs write split (HR Executive reads, cannot write); full CRUD; duplicate `code` → `422` with a **field** error; every required field reported at once, not one per submit; search / filter / pagination; a department with employees cannot be archived; unknown id → `404`, not `500` |
| `DesignationApiTest` | permission gate; CRUD; company-wide designation with no department; an archived department cannot be assigned; filters; designation with employees cannot be archived; bad status → `422` |
| `EmployeeApiTest` | permission gate; CRUD + soft delete; HR Executive creates but cannot delete; duplicate code/email; enum + reference validation; archived department rejected; primary site must belong to primary project; **reporting line cannot loop**; HR Executive may maintain the roster but not the payroll figure; Finance may read the figure but not edit; the list projection never carries a salary at all; filters + pagination; unknown id → `404` |
| `EmployeeRelationshipTest` | department/designation/user/reporting-manager links, one-to-one user link, soft vs hard delete behaviour, structured name, status vocabulary, uniqueness, soft-deleted rows hidden |
| `EmployeeVisibilityTest` | **row-level scope**: a Project Manager reaches only their own workforce (and anyone posted to their project); a Site Supervisor sees their site only; an ordinary employee reads *themselves* and nothing else; Management reads all but cannot write; posting history scoped the same way |
| `ProjectApiTest` | permission gate; read vs write split (HR Admin reads, Project Manager writes); date-order rules including *"end date with no start date"*; shortening a project cannot push its end before the stored start; duplicate code handling; filters; project with sites cannot be deleted; a Site Supervisor sees only projects owning a site they run |
| `SiteApiTest` | permission gate; read vs write split; latitude range; **geofence radius bounds come from config, not a constant**; half a geofence rejected; site must belong to a real project; supervisor ≠ manager; filters; site with assignment history cannot be deleted; Site Supervisor sees only their own sites |
| `ProjectSiteRelationshipTest` | project owns many sites, restrictive cascade, manager/supervisor are employees, soft vs hard delete of the reference, site belongs to a shift |
| `AssignmentApiTest` | permission gate; `created_by` recorded; site must belong to the named project; required fields; **a new primary posting closes the previous one without erasing it**; a temporary posting leaves the primary alone; a posting can be closed; history cannot be rewritten; an ended posting cannot be reopened; **no route deletes posting history**; Site Supervisor may post only to sites they run; list filters |
| `AssignmentHistoryTest` | history survives soft deletion of the site and *blocks* hard deletion; same for the employee; `created_by` optional for system imports; type/status vocabularies enforced; the end helper is idempotent |

### 3.3 Attendance ✅ (Phase 5 + selfie hardening — 131 tests)

Nine files: `AttendanceCheckInTest` (27), `AttendanceVisibilityTest` (19),
`WorkingTimeCalculatorTest` (18), `SiteVisitTest` (15),
`AttendanceCheckOutTest` (13), `GeofenceTest` (11),
`AttendanceStatusCalculatorTest` (8), `MovementTimelineTest` (7),
**`AttendanceSelfieSanitizationTest` (13)**.

Every test runs against `hrms_testing` only, and the sanitisation tests call
`Storage::fake('local')` so not one fixture photograph is written into the
real private storage.

#### Check-in

| # | Scenario | Expectation | Test |
|---|---|---|---|
| 1 | Check-in **inside** geofence | `201`, row created, `check_in_distance` computed **by the server** | `a_valid_check_in_records_a_derived_attendance_row` |
| 2 | Check-in **outside** geofence | **`422` `errors.location.0`, no row** | `a_point_outside_the_geofence_is_rejected` |
| 3 | Exactly at radius boundary | `201` (inclusive) | `a_point_inside_the_radius_is_allowed_and_its_distance_is_measured` |
| 4 | Accuracy worse than the ceiling (100 m) | `422`, no row — a fix that imprecise cannot prove anything | `a_gps_reading_worse_than_the_configured_ceiling_is_rejected` |
| 5 | Missing accuracy (null) | **not** treated as a bad reading — the ceiling applies to what was reported | `an_absent_accuracy_reading_is_not_treated_as_a_bad_one` |
| 6 | `latitude`/`longitude` out of range, or `0,0` | field-level `422` **before** any distance is computed | `an_out_of_range_coordinate_is_rejected_as_a_field_error`, `the_zero_zero_fix_is_rejected_as_invalid_not_as_far_away` |
| 7 | Site with no coordinates configured | `422` saying so, not a measurement of nothing | `a_site_with_no_location_cannot_be_checked_in_at` |
| 8 | Site radius null | falls back to the seeded default (100 m) | `a_site_without_a_radius_falls_back_to_the_seeded_default` |
| 9 | Duplicate check-in same day | **`409`, exactly one row** — the unique index, not a pre-query | `checking_in_twice_on_the_same_day_is_refused` |
| 10 | Unclosed yesterday | `409` — a day must be closed before a new one opens | `an_unclosed_yesterday_blocks_a_new_check_in` |
| 11 | Client sends `employee_id` / `attendance_date` / `status` | ignored — **the session decides who is recorded** | `the_session_decides_who_is_recorded_not_the_payload` |
| 12 | Client sends `source: manual` | never accepted as `manual` | (covered by 11) |
| 13 | Check-in without a selfie | `422` | `a_selfie_is_required` |
| 14 | File that is not an image | `422` naming the field | `a_file_that_is_not_an_image_is_refused` |
| 15 | Oversized image | `422` | `an_oversized_selfie_is_refused` |
| 16 | No session | `401` | `check_in_requires_a_session` |
| 17 | Rate limiter present on the four writes | config-driven (`attendance` 30/min), not an inline literal | `the_attendance_write_throttle_is_configured_rather_than_inlined` |

#### Selfie sanitisation & access (13 tests — `AttendanceSelfieSanitizationTest`)

Added by the post-Phase 5 hardening pass. Every row below asserts the file
side too: **a rejected request leaves no attendance row *and* no file.**

| # | Scenario | Expectation | Test |
|---|---|---|---|
| 1 | Valid JPEG | `201`; stored as `attendance-selfies/{employeeId}/{uuid}.jpg`, bytes are a complete JPEG (`FFD8` … `FFD9`) | `a_valid_jpeg_is_accepted_and_stored_under_a_server_minted_name` |
| 2 | Client-supplied filename (`my holiday selfie.jpg`) | ignored — the stored name carries none of it, no spaces | `the_client_chosen_filename_never_reaches_the_disk` |
| 3 | Valid PNG | accepted **and re-encoded to JPEG** — `.jpg` on disk, PNG magic absent; one format whatever arrived | `a_png_is_accepted_and_re_encoded_as_a_jpeg` |
| 4 | Not an image at all (`this is not a photograph`) | `422` `errors.selfie[0]` = "That file is not a readable image.", nothing written | `a_file_that_is_not_an_image_is_refused_before_anything_is_written` |
| 5 | Non-image **renamed** to `.png` | `422` with the same message — the extension is not the arbiter | `a_non_image_renamed_to_an_image_extension_is_refused` |
| 6 | Same, as a **real** upload (MIME sniffed from bytes, as production does) | `422` — two independent answers, both "no" | `a_non_image_renamed_to_an_image_extension_is_refused_by_the_mime_sniff_too` |
| 7 | Oversized real image | `422` `errors.selfie[0]` = "That selfie is too large…" | `an_oversized_image_is_refused` |
| 8 | Pixel count over `selfie_max_pixels` | `422` "That image is too large to process." — the byte ceiling alone does not bound the buffer | `an_image_larger_than_the_pixel_budget_is_refused` |
| 9 | JPEG carrying an EXIF block with a GPS fix, make and model | the **stored** bytes contain none of the markers and no `Exif` | `exif_and_camera_metadata_do_not_survive_sanitisation` |
| 10 | What is written vs what was sent | not byte-identical to the upload; exactly one file on the disk | `only_the_sanitised_image_is_stored_never_the_original_upload` |
| 11 | Read-back | no path in any response, `Content-Type: image/jpeg`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff` | `a_stored_selfie_is_reachable_only_through_the_policy_checked_route` |
| 12 | No session | `401` on both `get` and `getJson` | `an_unauthenticated_request_cannot_fetch_a_selfie` |
| 13 | Another employee | `403`, and their own list does not contain the id | `another_employee_cannot_fetch_this_selfie` |

*Not covered, and why:* EXIF **orientation** is discarded rather than baked
into the pixels, because the Flutter compressor already strips EXIF before
upload — so both paths behave identically. PHP cannot author an EXIF block
in a test fixture without hand-building a TIFF directory, so this is
documented rather than asserted.

#### Site assignment (the gate in front of every check-in)

| Scenario | Expectation | Test |
|---|---|---|
| No assignment to that site | **`403`** | `someone_with_no_assignment_to_the_site_is_refused` |
| Assignment ended | `403` — an ended row authorises nothing | `an_ended_assignment_does_not_authorise_a_check_in` |
| Assignment starts tomorrow | `403` | `an_assignment_that_has_not_started_yet_is_refused` |
| Temporary assignment, inside its dates | **`201`** | `a_temporary_assignment_authorises_a_check_in` |
| Additional assignment | `201` | `an_additional_assignment_authorises_a_check_in` |
| No assignment rows at all → profile `primary_site_id` | `201` — the fallback exists *because* the roster may only carry a primary | `the_profile_primary_site_authorises_a_check_in_when_no_rows_exist` |
| Any assignment row exists → profile fallback is silent | `403` — one closed row beats the profile pointer | `a_closed_assignment_row_beats_the_profile_fallback` |
| Assignment points at a site in a **different project** | `403` | `an_assignment_pointing_at_another_projects_site_is_refused` |
| Site `inactive`, or employee `inactive` | `403` | `an_inactive_site_refuses_check_in`, `an_inactive_employee_refuses_check_in` |
| Authenticated user with no employee profile | `403` — nobody to record | `an_account_with_no_employee_record_cannot_check_in` |

#### Check-out

| # | Scenario | Expectation | Test |
|---|---|---|---|
| 1 | Normal check-out | `200`, working/break/late/early/overtime all derived, status finalised | `check_out_finalises_the_day_with_derived_figures` |
| 2 | Check-out **without** check-in | **`422` `errors.check_out.0`** | `check_out_without_a_check_in_is_refused` |
| 3 | Second check-out | `409` | `a_second_check_out_is_refused` |
| 4 | Check-out at a **different site** | **`422` `errors.site_id.0`** — the day belongs to one site | `checking_out_at_another_site_is_refused` |
| 5 | Check-out outside the geofence | `422`, gated by `hrms.attendance.validate_checkout_geofence` | `checking_out_outside_the_geofence_is_refused` |
| 6 | Left early | status `incomplete`, `early_departure_minutes` recorded | `leaving_early_marks_the_day_incomplete_and_records_how_early` |
| 7 | Late start, but full hours worked | stays `late` — **not** downgraded to `incomplete` | `a_late_morning_that_adds_up_to_a_full_day_stays_late_not_incomplete` |
| 8 | Stayed past minimum + threshold | provisional `overtime_minutes` booked | `staying_on_books_overtime_past_minimum_plus_the_threshold` |
| 9 | Overnight shift | one continuous window across midnight | `an_overnight_shift_is_measured_as_one_window_across_midnight` |
| 10 | Schedule ended unattended | `missing_checkout` written **lazily by `attendance/today`**, not by a scheduler | `the_missing_checkout_flag_is_written_when_the_schedule_ends_unattended` |
| 11 | Yesterday still open | can still be closed | `an_old_open_day_can_still_be_closed` |
| 12 | Replay of an offline check-out | original row returned, no second write | `a_replayed_offline_checkout_returns_the_original_row` |

#### Status vocabulary (`AttendanceStatusCalculator`, 8 tests)

`present` / `late` / `incomplete` / `missing_checkout` / `manually_adjusted`
are asserted as the **complete** vocabulary, and the precedence rule is
tested from both sides: a short day that also started late is
`incomplete` (the missing hours are what an employee can fix), while a full
day that started late stays `late`. `manually_adjusted` is asserted to
exist with no writer yet.

#### Visibility & selfies (19 tests)

An employee lists only their own rows and cannot fetch a colleague's;
`attendance.view` alone grants **own rows and no others**; HR Admin reads
everything; HR Executive, Site Supervisor and Project Manager are scoped
exactly as `config('hrms.visibility.attendance')` says; a listed supervisor
who runs no site still reads only their own day; a role without
`attendance.view` gets `403`; `GET /attendance/today` is self-only; filters
are applied *after* the scope, so a client-supplied `employee_id` cannot
reach outside it. On the photo: the response never carries a storage path,
a persisted path containing `..` is never served, a row with no selfie says
so instead of failing, and the route serves the image with
`Cache-Control: no-store`.

### 3.4 Geofence & site-movement tests ✅ (Phase 5)

#### Geofence (11 tests)

Same coordinates → `0.0` m; a known pair matched against a reference value;
the fence is as wide as **the site** says, not as wide as a constant; the
`0,0` fix is rejected as *invalid* rather than as *very far away*; the
accuracy ceiling is read from config rather than restated as a literal; a
site without a radius falls back to the seeded default; and the distance
formula is asserted to agree with the site's own geofence helper — so the
PHP used for the decision cannot silently drift from the one used for the
explanation.

#### Site visits & movement (22 tests)

Start records a bounded two-point episode; the session, not the payload,
decides who is visiting; a second visit cannot start while one is open;
outside the geofence → `422`; unassigned site → `403`; `purpose` is
required; a replayed offline start returns the original row; end closes
with its own endpoint and a duration, cannot be ended twice, cannot be
ended from outside the geofence, and cannot be ended by anyone but the
visitor; `site-visits/today` is self-only; the list is behind
`attendance.view` and scoped by it; a Site Supervisor reads only the sites
they run. The movement timeline reads as one chronological list of
`check_in` / `site_visit_start` / `site_visit_end` / `check_out`, contains
**positions nowhere** (no coordinates leak into it), is self-only, treats
"nothing happened today" as an empty list rather than an error, and keeps
yesterday out of today's story.

**The single most important test:**

```
Employee outside site geofence  →  check-in rejected  →  no attendance record
```

**Deliberately not tested yet:** an actual `429` body for the attendance
limiter (the configuration *is* asserted; the shared envelope behaviour is
covered by `ApiErrorHandlingTest::test_repeated_login_attempts_return_a_429_envelope`),
and any override/audit behaviour — there is no override endpoint to test.

### 3.5 Leave ✅ (Phase 6 — `LeaveRequestTest`, `LeaveCertificateTest`)

**`LeaveRequestTest` — 22 tests**

| Test | Expectation |
|---|---|
| Day count is derived, not a client claim | `requested_days` comes from `LeaveDayCalculator` regardless of what was posted |
| Weekends are not leave days | a Mon–Fri range over a weekend counts five |
| A public holiday inside the range is not counted | holiday excluded from `working_days` |
| A site holiday excludes that site only | the same date still counts for another site |
| An inactive holiday still counts as working time | retiring a day changes the maths |
| Submitting reserves the days; rejecting gives them back | balance `used`/`pending` move and reverse |
| Beyond entitlement | `422` on submit, nothing reserved |
| Unpaid leave | never refused for balance reasons — that is what `is_paid = false` means |
| Overlapping ranges | `422` before a second claim can exist; `lop` counts as occupying |
| Chain walks in order, completes at the last step | status → `approved` only at the end |
| A workflow can be configured and changes who approves | the *configured* chain materialises, not the default |
| **Nobody approves their own request** | `403` even when the chain points at them |
| Without `leave.approve` | `403` from the middleware, before the policy |
| Pending → cancelled | `200`, reservation released |
| A submitted request can no longer be edited | `403` on `PUT` |
| An employee only reads their own requests | row scope |
| Leave types are rows, not rules in the services | changing a row changes the behaviour |
| Approval workflows configurable through their own endpoint | `approvals.manage` only |
| Balance summary: own only | `leave.balance.view`, scoped |
| Only HR corrects a balance by hand | `leave.balance.manage` |
| Holiday calendar readable by everybody, written by HR only | `GET` open, `POST` gated |
| A site holiday needs a site | `422` |

**`LeaveCertificateTest` — 11 tests**

| Test | Expectation |
|---|---|
| Sick leave demands a certificate; deadline runs from the last day | `certificate_due_at` frozen at submit |
| The leave type's own deadline beats the organisation default | `document_deadline_days > 0` wins |
| The organisation default applies when the type names none | `leave.sick_certificate_deadline_days` = 2 |
| Stored privately, **path never leaves the server** | no `certificate_path` in any response body |
| Readable only by someone who may read the request | `403` for a colleague |
| A file that is not a certificate | `422` on `errors.certificate[0]`, **nothing written** |
| Missed deadline → `lop` | status, `lop_days`, `lop_reason`, `lop_applied_at`, balance release |
| **Running the job again converts nothing a second time** | idempotent — `certificate_checked_at` |
| A certificate filed on the deadline stops the conversion | the sweep respects the boundary, not a timezone guess |
| Converting an *approved* request releases the days it already spent | `used` goes back |
| A certificate cannot be uploaded after the request became `lop` | `403` |

> **No audit row and no notifications are asserted here, because neither
> exists.** §4 of this document used to say "audit row exists / 2
> notifications queued"; those were plans, not tests. What is asserted is the
> event hook: `LeaveConvertedToLop` is faked and observed.

### 3.6 Overtime ✅ (Phase 6 — `OvertimeTest`)

| Test | Expectation |
|---|---|
| A claim is a **draft** and ignores fields the client does not own | `status`, `payroll_eligible`, `approved_minutes` are server-set |
| Overtime only for a day that happened | a future `overtime_date` is `422` |
| Chain walks in order; only the current step may sign | `403` off-step |
| The chain is configurable | overtime need not walk three links |
| Nobody approves their own claim | `403` even when the chain says so |
| A refusal is recorded and leaves nothing payable | `payroll_eligible` stays `false` |
| The approved amount is capped by what was asked | `approved_minutes > requested_minutes` → `422` |
| A draft can be edited; anything settled cannot | `403` on `PUT` |
| A claim can be cancelled; a settled one cannot | `403` |
| Without `overtime.approve` | `403` from the middleware |
| An employee reads only their own claims | row scope |
| **Only a completed claim is payroll-eligible, and the filter agrees** | `?payroll_eligible=true` returns exactly those |
| A cancelled or refused claim is never payroll eligible | the flag is written on approval and nowhere else |

**Deliberately untested, because it does not exist:** a payroll line.
`payroll_eligible` is stored and filtered; nothing in Phase 6 computes money
from it, and asserting a payroll total would be asserting a feature that is
not there.

### 3.6a Timesheets ✅ (Phase 6 — `TimesheetTest`)

| Test | Expectation |
|---|---|
| A timesheet is a faithful copy of one attendance day | minutes and hours match the source |
| **Generating the same window twice refreshes, not duplicates** | one row per `(employee, date)` |
| The status is a property of the day, not of the request | `open`/`complete`/`incomplete` derived |
| A nonsensical range is refused before anything is written | `422`, no partial window |
| An employee reads only their own working days | row scope |
| A supervisor sees the sites they run and no others | `Visibility` scope |
| Deriving a period is administrative | `timesheets.manage` |
| **There is no endpoint that can author a timesheet** | `POST /timesheets` → `405`, no `PUT`/`PATCH`/approve route |
| The list narrows by date, status, site, project and person | every filter honoured |

### 3.6b Holidays ✅ (Phase 6 — `HolidayApiTest`)

| Test | Expectation |
|---|---|
| Readable by anybody, writable only by HR | `GET` with no permission succeeds; `POST` needs `holidays.manage` |
| A reader gets public and company days **plus the sites they belong to** | visibility scope, not a raw dump |
| Narrows by date, type, status and name | every filter honoured |
| A scope on a date holds only one holiday | duplicate `(date, type, site_id)` → `422` |
| A site holiday without a site | `422` |
| **Retired by status, and there is no way to delete it** | `DELETE /holidays/{id}` → `405` |
| Editing cannot make it collide with another | the duplicate check excludes the row being edited |

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
| **Sick certificate deadline ✅ Phase 6** | `LeaveCertificateTest::test_a_missed_deadline_converts_the_request_to_loss_of_pay` — `Carbon::setTestNow()` past `certificate_due_at`, run `EnforceSickCertificateDeadlines` directly, assert `status = lop` |
| **Same job, run twice ✅** | `test_running_the_deadline_job_again_converts_nothing_a_second_time` — the second run is a no-op because `certificate_checked_at` was set |
| **Same job, filed in time ✅** | `test_a_certificate_filed_on_the_deadline_stops_the_conversion` |
| **Same job, already approved ✅** | `test_converting_an_approved_request_releases_the_days_it_already_spent` — the balance goes back |
| **Registration** | `php artisan schedule:list` shows `17 * * * * App\Jobs\EnforceSickCertificateDeadlines` with `withoutOverlapping(60)` and `onOneServer()` |
| LOP conversion event | `Event::fake([LeaveConvertedToLop::class])` — required because the event `implements ShouldDispatchAfterCommit` under `RefreshDatabase` |
| Document expiry reminder | ⬜ Not built — no documents module yet |
| Training expiry reminder | ⬜ Not built |
| Missing check-out detection | **No scheduler exists** — the flag is written lazily by `AttendanceService::flagMissingCheckouts()` the moment anyone reads `GET /attendance/today`. Covered by `AttendanceCheckOutTest::test_the_missing_checkout_flag_is_written_when_the_schedule_ends_unattended`, using `Carbon::setTestNow()` rather than a run |
| Attendance reminder | ⬜ Not built — arrives with notifications |

**How the job is made safe to run on a schedule:** `$tries = 3`,
`$uniqueFor = 3600` plus `Schedule::job(...)->withoutOverlapping(60)` — two
independent guards, because a queue worker restarted mid-run and an
overlapping cron entry are different failures. The idempotency itself is in
the data (`certificate_checked_at`), not in the lock: even a third
concurrent run with both locks defeated converts nothing.

Use `Queue::fake()` / `Notification::fake()` / `Event::fake()` and
`Carbon::setTestNow()`.

---

## 5. Flutter Test Matrix

### 5.1 Unit ✅ (Phases 3–6)

| Target | Tests | File |
|---|---|---|
| `ApiException` envelope parsing | 401/422 read as the server wrote them; `Retry-After` parsed; timeout **never** blames the user; a non-envelope body is never shown as prose | `test/core/network/api_exception_test.dart` |
| `AuthController` state machine | initial status, restore outcomes (accepted / rejected / unreachable / empty), login success + failure paths, 422 field map, throttle wait, `dismissFeedback`, logout (server ok / unreachable / no session), session-rejected broadcast | `test/features/auth/auth_controller_test.dart` |
| Validators | empty form says what is missing; the typed credentials are what reaches the API | `test/features/auth/login_screen_test.dart` |
| List envelope | items + every metadata field; `has_next` derived when the server omits it; nothing left to load on the last page; a malformed payload **refused** rather than rendered as an empty list | `test/core/data/page_result_test.dart` |
| Phase 4 models | `Employee`, `Site`, `Department`, `Project` `fromJson` — a list projection with no salary, a detail projection that reads one only when allowed, an unset geofence staying `null` instead of `0`, decimals that arrive as **strings** | same file |
| `PermissionScope` | an unsigned-out session fails closed; `can`/`canAny`/`canAll`; **`employees.view` alone never implies `employees.salary.view`**; the seeded role bundles read the way the screens phrase them; a typo in a permission string reads as *denied* | `test/core/permissions/permission_scope_test.dart` |
| `TodayStatus` / `AttendanceRecord` | the `GET /attendance/today` payload parsed field by field; `sites[]`; the `shift` block; `can_check_in` / `can_check_out` / `can_start_site_visit`; decimal strings read as doubles; a null `attendance` meaning "nothing yet" rather than a crash; the display time sliced, **never shifted to the device's timezone** | `test/features/attendance/today_status_test.dart` (13) |
| `LocalGeofence.assess` | inside / on the boundary / outside; radius from the server, not a literal; unusable accuracy and null coordinates reported as such rather than as "far away"; agrees with the server's own formula on known pairs | `test/features/attendance/local_geofence_test.dart` (12) |
| `OfflineQueue` | enqueue → read → mark synced round-trip; **`enqueue(clientEventId:)` reuses the key it was given**; a retry re-sends the same id; corrupt JSON in storage yields an empty queue instead of a throwing app; selfie files move with their event; `markFailed` keeps the reason | `test/features/attendance/offline_queue_test.dart` (13) |
| `AttendanceController` | loading / ready / error; every location state; **duplicate submit blocked while one is in flight**; 403 shown as a refusal; 422 rendering the server's own field message; no-fix refuses to send rather than posting (0,0); check-out; offline queueing and replay with the same `clientEventId` and `source: offline`; a rejected replay marked `failed`; site visits including an empty purpose; a purpose controller disposed only after its dialog has finished | `test/features/attendance/attendance_controller_test.dart` (27) |
| `OvertimeRequest` | **`payableMinutes` is `approved ?? requested` and only when the status is `approved`** — an absent approval is not a refusal; draft/pending/rejected/cancelled are never payable whatever was granted; `payroll_eligible` is read from the server and **never inferred from `status`** (an approved-but-not-eligible claim is possible and must read as such); `is_payroll_eligible` reads the same as `payroll_eligible`; status labels spell nothing out to the user (`pending` → "Awaiting approval"); `requestedLabel` / `approvedLabel` in both units; `isEditable` is true for a draft and false for anything settled | `test/features/overtime/domain/overtime_request_test.dart` (9) |
| `LeaveRequest` / `LeaveCertificate` | certificate facts arrive ready-made rather than being re-derived on the phone: *required but not yet due* shows "Certificate due 2026-10-04" with a file action, *overdue* shows the server's flag and its chip, and a type that never asks for one draws **no certificate block at all** | `leave_detail_screen_test.dart` (group "the certificate") |

**Two non-obvious guarantees worth keeping under test:**

- *Restore asks the server **once**.* `AuthController` may be read by the
  router, the splash screen and the session-rejected listener in the same
  frame; `build()` must not fan out a request per reader.
- *A rejection is announced once.* If the server drops a token, both the Dio
  interceptor and the controller can notice. The sign-in form's own message
  must survive.

### 5.2 Widget ✅ (Phases 3–6)

| Screen | States verified |
|---|---|
| Login | empty/invalid submit, success → home, 401 as **one banner with no field marked**, 422 with `forceErrorText` under the named field, 429 with countdown, password masked until asked for, editing clears a superseded message |
| Splash | holds `/` while restoring — `advance()` pumps instead of `pumpAndSettle()`, because the restoring spinner is intentionally endless |
| Employees list | the four states in order — spinner while in flight, *empty* is distinct from *unknown*, failure with a working retry, **a failed refresh keeps the rows and says so on top**; search sent trimmed; status filter sends only the status chosen and removes the key when cleared; load-more appends rather than replaces; **a session without `employees.view` is drawn as a lock and never asked for the list** |
| Employee detail | a 403 drawn as a refusal (lock, not error) with retry still offered; salary shown only when the permission **and** the payload both say so; edit/delete only for the right permissions |
| Employee form | salary field not drawn without the permission; `salary` key **absent from the body** when it is not on screen and present with the right number when it is; untouched create never reaches the server; a 403 while editing says so plainly |
| Department form | local validation before any request; trimmed values sent, blank description sent as `null`; **422 lands on the field it names** and clears on the next attempt; 403 shown in the banner with no field blamed; edit loads the record (including its `inactive` status) and puts it back with the same id |
| Attendance (22 tests) | loading → error-with-retry → ready; CHECK IN shown before a punch and CHECK OUT after; one assigned site rendered as a line, several as a picker; the location banner in every state — denied (with a re-ask), permanently denied (with Settings and the pre-filled "Allow only while using the app" guide), **GPS switched off named separately from a refusal**, and an advisory "outside the radius" warning; the whole selfie flow (open → permission → front camera → capture → preview → retake → compress → submit) plus *cancel* and *retake* proving no upload happened; camera denied and **no camera at all**; a 403 drawn as a refusal; a 422 showing the server's sentence; **while a request is in flight every action is disabled**; the offline queue card with "Sync now"; the purpose dialog refusing an empty purpose; ending a visit |
| Leave list | rows say which leave, over which days, and where it stands; the status filter sends `status` and **removes the key** when cleared (absent ≠ empty); *empty* drawn as empty; **a session without `leave.view` is drawn as a lock and the list controller is never built**; *Apply leave* and *Balances* appear only for `leave.create` / `leave.balance.view` |
| Leave detail | **the action set is a function of state and permission** — draft offers submit/edit/cancel and never approve; pending offered to an approver carries approve + reject and **no cancel** (cancel is the owner's hatch, and the server would 403 an approver anyway); pending offered to its owner carries cancel and no decision; a finished request offers nothing; the certificate block appears only for a type that requires one, and the file button only for `leave.create`/`leave.manage`; reject refuses to send an empty reason and then sends the one typed; a refused action is reported in the banner, **not as a field error** |
| Holidays list | readable with any permission at all (no gate — `HolidayPolicy::viewAny` says yes); rows show the scope, not just the type; *add* and row navigation appear only for `holidays.manage`; the type filter is a query parameter and clearing it removes the key; retired days stay on the list |
| Timesheets list | rows carry day, owner, worked hours and the day's status; overtime shown **separately**, never folded into the total; *generate* only for `timesheets.manage` and it really calls the server; **a session without `timesheets.view` is a lock and never asked for rows** |
| Overtime list | rows show date, ask and owner; the payroll flag is its own chip and not a status; `?payroll_eligible=true` toggled off **removes the key**; the claim door only for `overtime.create`; **a session without `overtime.view` is a lock and never asked for claims** |

> **Don't read `TextFormField.obscureText`** — it is not public. Read the
> widget's own `TextField.obscureText` field instead.
>
> **Avoid `pumpAndSettle`** anywhere the splash spinner is visible: it never
> settles. Eight × 100 ms pumps (`advance()`) is enough for any transition in
> this feature.
>
> **A `tap()` that lands off-screen does not fail on the tap** — it warns, the
> handler never runs, and the assertion afterwards fails for an unrelated
> reason. `useTallScreen()` resizes the surface for the long forms.
> Likewise, an unbuilt `ListView` row is not a row a finder can see, so the
> load-more test runs on a surface tall enough to build all of them.

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
| 403 response | shows "not authorized", **does not log out** — the lock banner stays, the form stays editable, the session is untouched | ✅ Phase 4 (`employee_detail_screen_test`, `employee_form_screen_test`, `department_form_screen_test`) |
| Network loss / offline queue | only a transport failure queues; the event keeps its `clientEventId`; "Sync now" replays oldest-first; a refusal marks `failed` and is shown, not retried | ✅ Phase 5 (`attendance_controller_test`, `offline_queue_test`) |
| Permission hidden | control absent for a user without the permission, **and no request is made for it** | ✅ Phase 4 (`permission_scope_test`, employees list) |

### 5.4 What NOT to test in Flutter

Business rules (geofence acceptance, balance calculation, payroll maths) are tested in
Laravel. Flutter tests confirm **rendering and state handling** only.

**Doubles, not sockets.** Every Flutter test runs against
`test/support/fakes.dart` — `InMemoryTokenStore`, `FixedDeviceIdentity`,
`FakeAuthRepository` — and Phase 4 added `test/support/phase4.dart`: one
generic `Scripted<T>` repository used five times, which records `listCalls`,
`lastQuery`, `lastBody` and `lastId`, can be told to fail once and then
recover, and can park on a `Completer` so a test can look at the loading state
a real double that answers immediately never renders. `scopedPhase4(...)`
overrides the permission scope directly, so a widget test does not have to
drive the whole auth restore to ask "what does an HR Executive see?".

Phase 5 added `test/support/attendance.dart` for the same reason, with three
hardware-shaped fakes:

| Fake | Stands in for | What it lets a test assert |
|---|---|---|
| `FixedLocationGateway` | `geolocator` | each of the six `LocationStatus` values, and a *refusal* that never sends a request |
| `ScriptedCamera` | `camera` + the front camera | every `CameraStatus`, and a capture returning a **real 1×1 PNG** — an engine cannot rasterise a frame under fake async, so the bytes must be genuine rather than a placeholder |
| `InMemoryOfflineQueueStore` | `SharedPreferences` | enqueue → replay → mark, without touching a platform channel |

`useTallScreen()` from `test/support/phase4.dart` is required for the
attendance screen: it scrolls, and an unbuilt widget below the fold is not a
widget a finder can see. The Phase 6 leave detail, timesheet and overtime
screens need it for the same reason — they are long, and an action button
outside the viewport is not in the tree at all, so a `find` that missed one
would fail for a reason unrelated to permissions.

Phase 6 added `test/support/phase6.dart`: `ScriptedLeave`,
`ScriptedHolidays`, `ScriptedTimesheets` and `ScriptedOvertime` (each with
the extra methods their own contracts need — transitions record
`lastTransition` / `lastRemarks`, the certificate records `lastUpload`, and
generation records `generateCalls`), plus `scopedPhase6(...)` and
`phase6Router()`. It re-exports `advance`, `useTallScreen`, `forbidden403`
and `notFound404` so a Phase 6 test needs exactly one import.

`flutter_secure_storage` talks to the platform keychain over a method channel,
and `ApiAuthRepository` would need a live server; neither has anything useful
to say inside a widget test.

---

## 6. Critical Scenarios (must never regress)

### Scenario A — Outside geofence
```
Employee opens attendance
  → gets GPS outside the site radius
  → taps CHECK IN
  → Laravel rejects (422 errors.location.0)
  → NO attendance record created
  → NO selfie file left on disk
  → clear message naming the site and the distance
  → a second tap does not create one either (duplicate check-in → 409)
```

### Scenario B — Missing medical certificate ✅ (Phase 6, tested)
```
Employee submits sick leave on a type with requires_document
  → certificate_due_at frozen at submit (type deadline, else setting = 2 days)
  → does not upload a certificate
  → EnforceSickCertificateDeadlines runs hourly at :17, queued, unique
  → status → lop, lop_days = requested_days
  → lop_reason + lop_applied_at recorded
  → the paid leave type's balance reservation is released
  → LeaveConvertedToLop dispatched after commit  (event hook only)
  → certificate_checked_at stamped — a second run converts nothing
  → runs even if the app is closed
```
**Deliberately absent from this scenario:** an audit row and any
notification. Neither exists in this system, and TESTING must not describe
either as if it did (see SECURITY §8).

### Scenario C — Offline duplicate prevention
```
Employee checks in with no signal
  → the request is attempted, and only the transport failure queues it
  → queued with clientEventId minted BEFORE the first attempt
  → employee taps "Sync now"
  → upload succeeds → removed from the queue
  → upload RETRIED (same clientEventId, source=offline)
  → server sees the same client_event_id
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

### Scenario E — Attendance privacy (added in Phase 5)
```
Employee A holds attendance.view, employee B does not
  → GET /attendance returns ONLY A's rows
  → a client-supplied employee_id cannot widen it
  → GET /attendance/{B's row} → 403
  → GET /attendance/{B's row}/selfie → 403
  → the JSON of any row contains has_selfie, never a path
  → no permission anywhere lets an ordinary employee read a colleague's day
```

### Scenario F — A selfie that is not what it claims (hardening pass)
```
A client bypasses Flutter entirely and POSTs /attendance/check-in
  → a text file named .jpg            → 422, no row, no file
  → a real image renamed to .png      → 422, no row, no file
  → a valid PNG                       → 201, stored as <uuid>.jpg (JPEG bytes)
  → a valid JPEG carrying EXIF        → 201, stored bytes contain NO Exif,
     with GPS fix, Make and Model            NO GPS fix, NO camera identity
  → an image over selfie_max_pixels   → 422 — the byte ceiling does not
                                         bound the decode buffer
  → whatever arrives, disk holds exactly ONE file, at
    attendance-selfies/{employeeId}/{uuid}.jpg on the `local` disk
  → no response ever contains that path
  → unauthenticated GET /attendance/{id}/selfie → 401
  → another employee on the same route          → 403
```

Never regress: the sanitiser stores **only its own re-encoded output**. If
`SelfieStore` ever writes `$file` bytes again, Scenario F fails even though
every Phase 5 test still passes.

### Scenario G — Self-approval is impossible ✅ (Phase 6, tested)
```
An approver who is also the requester opens their own pending request
  → the coarse permission check passes (they do hold leave.approve)
  → ApprovalWorkflowService::authorize() compares the actor to the request owner
  → 403, before any status is written
  → the same holds when the workflow chain literally names them as step 1
  → and for a Super Admin, who holds every permission there is
  → the refusal is not a controller-level `if` — it is in the one method
    every transition passes through
  → the same refusal applies to an overtime claim, because both subjects
    go through the same authorize()
```

### Scenario H — Only the current step may act ✅ (Phase 6, tested)
```
A three-step chain is submitted; step 1 is waiting
  → the approver for step 2 calls approve → 403
  → step 1 approves → step 2 becomes waiting, request still pending
  → step 1 tries again → 403 (already decided, not re-decidable)
  → step 3 approves → status becomes approved, nothing left waiting
  → a workflow definition edited afterwards changes nothing for this request
    (the chain was materialised at submit)
  → an unresolvable step (a reporting manager the employee does not have)
    is skipped with a remark, never deleted, and never blocks submit
```

### Scenario I — A certificate that is not what it claims ✅ (Phase 6, tested)
```
A client bypasses Flutter entirely and POSTs /leave/{id}/certificate
  → 3 MB of bytes renamed note.pdf   → 422 on errors.certificate[0],
                                        no row updated, no file written
  → an image renamed .pdf            → 422 (mimetypes + CertificateContent)
  → a valid PDF                      → 201, stored as
                                        leave-certificates/{employeeId}/{uuid}.pdf
                                        on the `local` disk, name minted by
                                        the server — the client's filename
                                        is never read for storage
  → a file larger than the configured ceiling → 422
  → unauthenticated GET .../certificate      → 401
  → a colleague on the same route            → 403
  → the JSON of the leave request contains certificate facts only —
    never certificate_path, never a URL, never base64
  → a second upload replaces the first; the superseded file is deleted
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
> fakes in `test/support/fakes.dart`, `test/support/phase4.dart` and
> `test/support/attendance.dart`, so they stay green while a server is
> stopped — which is exactly when a regression shows up.

### Static analysis (run before every commit)
```bash
cd backend
./vendor/bin/pint --test          # Laravel code style
composer validate                 # composer.json integrity

cd mobile
dart format .                     # must report 0 changed files
flutter analyze                   # must report no issues
```

> `dart format .` from **`mobile/`**, not `mobile/lib` — the tests are code
> too. Run all three Flutter gates in this order; `flutter analyze` will pass
> on files the formatter would rewrite.

---

## 8. Coverage Targets

| Area | Target |
|---|---|
| Services (geofence, working time, attendance, site visits, leave, payroll) | **≥ 90%** |
| Policies / authorization | **≥ 90%** |
| Controllers | ≥ 70% |
| Flutter models + repositories | ≥ 80% |
| Flutter widgets | smoke tests on every screen |
| Overall backend | ≥ 75% |

Coverage is a floor, not a goal — the nine critical scenarios in §6 (A–I) matter more than
a number.

---

## 9. Definition of Done (per phase)

A phase is complete only when:

- [ ] New/changed endpoints have tests
- [ ] Authorization tested for each new endpoint (`403` for unauthorized roles)
- [ ] Validation failure paths tested (`422`)
- [ ] Critical scenarios still pass
- [ ] `php artisan test` green
- [ ] `route:list` shows only the routes that were meant to be added
- [ ] `migrate:status` shows every migration `Ran`, and on `hrms_testing` too
- [ ] `composer validate` and `vendor/bin/pint --test` pass
- [ ] `dart format .` reports no changes
- [ ] `flutter analyze` clean
- [ ] `flutter test` green
- [ ] Code style check passes
- [ ] No secrets introduced into the repository

---

## 10. Status

| Item | Status |
|---|---|
| Test strategy (this document) | ✅ Written |
| Development/testing database split (`hrms_laravel` vs `hrms_testing`) | ✅ Phase 2 safety cleanup |
| Backend test suite | ✅ **383 passed (2017 assertions)** — 75 Phase 2 + 45 Phase 3 + 70 Phase 4 + 131 Phase 5 (incl. selfie hardening) + **62 Phase 6** (22 `LeaveRequestTest` · 11 `LeaveCertificateTest` · 13 `OvertimeTest` · 9 `TimesheetTest` · 7 `HolidayApiTest`) |
| Flutter test suite | ✅ **215 passed** — 89 Phases 3–4 + 87 Phase 5 + **39 Phase 6** |
| Phase 6 registration checks | ✅ `php artisan route:list` (86 routes) · `php artisan schedule:list` shows `EnforceSickCertificateDeadlines` |
| `flutter analyze` / `pint --test` / `composer validate` clean | ✅ |
| CI pipeline running tests on every commit | ⬜ |
| Coverage measurement (needs xdebug/pcov) | ⬜ |
