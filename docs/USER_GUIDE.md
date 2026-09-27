# User Guide

> **Status:** Phase 5 — sign-in, the organisation screens, and GPS attendance
> with site visits exist and are usable. This guide describes the workflows
> each role will have once the corresponding phase ships. Sections are marked
> with the phase that delivers them; ✅ means it is in the app today.

---

## 1. About This System

This is an HR and workforce management system built for a construction / MEP company
with **multiple projects and multiple physical sites**.

It handles the full employee lifecycle — attendance with GPS and selfie verification,
movement between sites during the day, leave, timesheets, overtime, payroll, documents,
training and expenses — with **role-based access** so each person sees only what is
relevant to them.

---

## 2. Signing In

> ✅ Phase 3 — sign-in, session restore and sign-out. Forgot-password arrives with the UI in a later phase.

1. Open the app — it restores your session automatically if you already signed in
2. Enter your work email and password
3. Tap **Sign In**
4. If the password is wrong you get one message for both a bad password and an
   unknown address; nothing tells you which one it was
5. Too many attempts shows how many seconds to wait before trying again

Your session is remembered in the device's secure key storage. It survives the
app being closed and the phone going offline — an unreachable server will **not**
sign you out. Signing out, or an administrator disabling your account, does.

**Multiple devices:** each sign-in replaces that device's previous session, so
no one accumulates sessions they cannot see. You can review and revoke them
from *Settings → Sessions* (UI arrives in a later phase); changing your password from
any device immediately signs out every other one.

**Forgot password:** *not available yet.* The API exists but deliberately
answers "not available" until a mail server can actually deliver the link —
contact your administrator for a reset in the meantime.

**Security:** your password is never stored in plain text, and your session token is kept
in your device's secure key storage. If you suspect any issue, sign out — this revokes
your token immediately.

### 2.1 The home screen ✅ (Phase 4)

After signing in you land on **Home**. It draws one tile per module your role may
open — Employees, Departments, Designations, Projects, Sites — and nothing else.
A role that cannot open a module is not shown a door it would be refused behind.

Every module follows the same three screens:

| Screen | What it does |
|---|---|
| **List** | search box, filters (department, status, employment status), pull to refresh, **Load more** past the first page |
| **Detail** | the full record and what is connected to it, with **Edit** / **Delete** where your role allows |
| **Form** | create or edit; every message under a field came from the server, which owns the rules |

Four things worth knowing:

- **A list you may not open is never fetched.** Without the permission the
  screen draws a lock rather than an error — and no request was made on your
  behalf to be refused.
- **Being refused is not being signed out.** A *not authorized* message means
  that one action was not permitted for you; your session continues and the
  form keeps what you typed.
- **Salary is its own permission.** Opening the employee list never reveals
  anyone's pay — the field is not drawn at all, and a request carrying it
  would be rejected by the server.
- **Nothing disappears behind your back.** A department with employees, a
  project with sites, a site with assignment history: each refuses deletion
  and tells you what must be moved first.

---

## 3. Employee

### 3.1 Checking in ✅ Phase 5

Attendance is designed to take **one tap** — the screen is deliberately simple because
it is used outdoors on a construction site.

```
Today's Attendance

Whitefield Yard · Metro Line 3

GPS:
  ✓ Location detected
  ✓ Within allowed area (34 m of 100 m, advisory)

        [ CHECK IN ]
```

**What happens:**

1. Open **Attendance**
2. The app shows your assigned site (and a picker if you have more than one)
3. Allow location permission when asked — the reason is explained *before*
   the system prompt: a geofence needs to know where you are standing
4. Wait for the location check — you will see whether you are within the allowed area.
   The phone's measurement is **advisory**; the server measures again when your request
   arrives
5. The front camera opens → take a selfie
6. Preview it → **Retake** if needed, or confirm
7. Tap **Check In**

**After check-in:**

```
Checked in:  09:05
Working:     00h 12m

[ SITE VISIT ]   [ CHECK OUT ]
```

**If you are outside the allowed area:**

> *"You are 412 m from Whitefield Yard; the allowed radius is 100 m."*

No attendance is recorded and no photograph is kept. If you believe this is
wrong (GPS drift, wrong site assignment), contact your supervisor or HR.

**If the app says location is off:**

| Message | What it means | What to do |
|---|---|---|
| *"Location access is off for this app."* | You denied it once | Tap to be asked again |
| *"You chose not to share location."* | Denied permanently | Tap to open this app's Settings and choose **Allow only while using the app** |
| *"Turn on location services."* | You allowed the permission, but the phone's location switch is off | Pull down the quick settings and turn on Location |
| *"Your location is too inaccurate to prove you are here (±180 m)."* | The fix is weaker than the configured ceiling | Move into the open, away from the building; try again |

None of these crash the app, and none of them record anything.

**Check-out works the same way — at the site where you checked in.** The day
belongs to one site, so checking out somewhere else is refused with a clear
message. Moving to another site for part of the day is a **site visit**
(§3.2), not a second check-in.

**Two things the app will refuse, and why:**

- A second check-in on the same day → *"You have already checked in today."*
  One row per employee per day is enforced by the database.
- Checking out with no check-in → *"No check-in to close."*

### 3.2 Working between sites ✅ Phase 5

If you move to another site during the workday, record it so your time is attributed
correctly:

```
09:05   Check-in            Whitefield Yard
10:20   Site visit start    Metro Line 3 · Material delivery
11:05   Site visit end      Metro Line 3
18:02   Check-out           Whitefield Yard
```

1. Tap **Site Visit**
2. Select the site and enter the **purpose** (required — it is the reason the
   record exists), plus remarks if you like
3. Location is captured at that moment
4. When you leave, tap **End Visit** — location is captured again, and the
   visit gets its duration

Your daily timeline shows the full movement history (`GET /movement/today`),
and only your own.

A visit cannot start while another one is still open, and a visit started
inside the boundary cannot be ended from outside it.

> **Note:** the app does **not** track you continuously in the background. It
> has no background location permission at all. Location is captured only
> when you press one of these buttons, and nothing in between is recorded —
> there is no trail of where you were at 11:30.

### 3.3 Site activity report ⬜ Phase 7

Record what was actually done on site:

- Date, project, site
- Work category and work performed
- Progress percentage
- Materials used, manpower, equipment
- Issues and safety concerns
- Photos (multiple)
- Remarks

### 3.4 Applying for leave ⬜ Phase 9

1. Open **Leave**
2. Check your balance — entitlement, used, pending, remaining
3. Tap **Apply**
4. Choose leave type, dates and reason
5. Submit

**Statuses you will see:**

| Status | Meaning |
|---|---|
| Draft | Saved, not submitted |
| Pending | Waiting for approval |
| Approved | Accepted |
| Rejected | Declined (reason provided) |
| Cancelled | Withdrawn by you |
| LOP | Loss of pay — e.g. a sick certificate was not submitted in time |

**Sick leave:** if you choose Sick Leave you may be required to upload a medical
certificate. The deadline is shown when you apply (default **2 days**). If you miss it,
the leave is converted to LOP automatically and both you and HR are notified.

### 3.5 Timesheet & overtime ⬜ Phase 8

- View your recorded hours for the period
- Request overtime with a reason
- Track approval status: *Supervisor → Project Manager → HR / Payroll*
- Only **approved** overtime is included in payroll

### 3.6 Expenses ⬜ Phase 11

1. Open **Expenses** → **New**
2. Choose date, project, site, category and amount
3. Add a description and a photo of the receipt
4. Submit

Track status: *Pending → Approved / Rejected*.

### 3.7 Payslips & documents ⬜ Phase 11

- View and download **your own** salary slips (PDF)
- Request a **salary certificate** for bank/visa purposes
- View your own documents (passport, visa, Emirates ID) and their expiry dates

> You can only ever see your own salary information.

### 3.8 Dashboard ⬜ Phase 12

- Today's attendance and current site
- Check-in / check-out times and working hours
- Leave balance and recent requests
- Latest salary slip
- Site visits
- Pending actions

---

## 4. Site Supervisor

> ✅ Phase 5 (attendance viewing) · ⬜ Phase 7–8 (site reports, overtime, timesheets)

| Capability | Detail |
|---|---|
| **Daily site report** | Workforce categories, total manpower, work planned/completed, materials, equipment, safety observations, delays, issues, photos, remarks |
| **Approve site activity reports** | Review reports from your team |
| **Create / approve overtime** | First step in the overtime approval chain |
| **Timesheet approval** | Verify hours for your team |
| **View your site's attendance** ✅ | Who was on site, and when — restricted to the sites you run. You also see visits to your sites, and you cannot read a day recorded anywhere else |

**Daily Site Report** can be exported as **PDF**.

---

## 5. Site Engineer

> ⬜ Phase 7

Similar to Site Supervisor, focused on technical reporting:

- Submit site activity reports with progress percentages
- Record materials, manpower and equipment
- Flag issues and safety concerns
- View site assignments for your project

---

## 6. Project Manager

> ✅ Phase 4 (project & site management) · ✅ Phase 5 (attendance & visits on your projects) · ⬜ Phase 12 (dashboard)

**Dashboard:**
- Projects and sites
- Workforce per site
- Attendance overview
- Site visits and site reports
- Overtime requests awaiting approval

**Manage (live today):**
- Project details and status (Planned / Active / On Hold / Completed / Cancelled)
- Sites within your projects, including geofence radius
- Approval of overtime for your projects
- **Attendance on the projects you manage** — every day recorded on your
  projects and their sites, with the GPS distance and the photograph where
  one was taken. A project's own days and nobody else's

> Scope is limited to projects assigned to you — the server narrows your list,
> so you are not shown projects you do not run. Asking for a colleague's
> record by id is refused with `403`, whatever the filter says.

---

## 7. HR Executive

> ✅ Phase 4 (employees, departments, designations) · ✅ Phase 5 (attendance viewing) · ⬜ for documents, leave

- Create and edit employee records
- Manage departments and designations
- Upload and organize employee documents
- Track document expiry (passport, visa, Emirates ID, certificates)
- Run onboarding and see missing documents
- View attendance across the company — every employee, every day, with the
  GPS distance and the photograph, filtered by date, project, site or status
- First-level leave approval (depending on configured workflow)

HR Executive may maintain the roster but **cannot delete an employee and cannot
see anyone's salary** — those two are separate permissions held elsewhere.

---

## 8. HR Admin

> ✅ Phase 4 (employee management, site assignments) · ✅ Phase 5 (attendance viewing + configuration) · ⬜ Phase 9 (leave)

Everything an HR Executive can do, plus:

| Capability | Detail |
|---|---|
| **Attendance management** ✅ | View all employees, dates, times, projects, sites, GPS, selfies and status — filtered and paginated |
| **Configure geofence radius** ✅ | Per site, no code changes needed |
| **Configure working rules** ✅ | Start time, grace period, break, minimum hours, overtime threshold — read from settings, never hard-coded |
| **Attendance override** | ⬜ **Not built yet.** Correcting an out-of-geofence or missing record is planned with a mandatory reason and an audit trail. Today the correct route is for the employee to re-check-in, or for you to note it with the record; nothing silently overwrites what happened |
| **Configure geofence radius** | Per site, no code changes needed |
| **Configure working rules** | Start time, grace period, minimum hours, overtime threshold |
| **Leave approval & configuration** | Entitlement, carry-forward, max days, documents, workflow |
| **Holiday calendars** | Public, company and site-specific holidays |
| **Shift management** | General, Morning, Evening, Night, Custom |
| **Site assignments** | Assign temporarily or permanently — history preserved |
| **Employee management** | Full CRUD with status changes |
| **Reports** | Attendance, leave, manpower, document expiry, movement |

---

## 9. Payroll Admin

> ⬜ Phase 11

- Run **monthly payroll** using approved attendance, overtime, leave and loan data
- Manage basic salary, allowances, bonus, deductions, LOP, advances
- Generate **salary slips** (PDF)
- Process **salary certificate** requests
- Manage **loans and salary advances** with installment schedules
- Protects payroll data behind permissions

**Payroll only uses approved data** — unapproved overtime or leave never enters a run.

---

## 10. Finance

> ⬜ Phase 11

- Review and approve **expenses**
- Verify receipts
- View loan and advance deductions for processing
- Access financial reports (payroll summary, expense report)
- No access to HR-only functions

---

## 11. Management

> ⬜ Phase 12

**Dashboard:**
- Total workforce
- Site manpower distribution
- Attendance trends
- Leave trends
- Overtime totals
- Payroll summary
- Project workforce breakdown

Read-only — plus the ability to view any report.

---

## 12. Super Admin

> ✅ Phase 4 (every module screen, through `*` permissions) · ⬜ roles / users / settings UI

- Full system access
- Manage roles and permissions
- Manage users and sessions
- Configure all system settings
- View **audit logs**
- Manage notification preferences and templates

> Super Admin should be used sparingly. Day-to-day administration belongs with HR Admin.

---

## 13. Notifications

> ⬜ Phase 12

You will be notified about:

| Event | Who receives it |
|---|---|
| Attendance reminder | Employee |
| Leave submitted / approved / rejected | Employee + approver |
| Sick certificate reminder | Employee |
| LOP conversion | Employee + HR |
| Salary slip available | Employee |
| Salary certificate status | Employee |
| Document expiry (passport, visa, ID, certificate) | Employee + HR |
| Training certificate expiry | Employee + HR |
| Site assignment | Employee |
| Overtime approval | Employee + approver |
| Expense approval | Employee + approver |

Notification preferences can be configured per user.

---

## 14. Working Without Internet

Construction sites often have poor connectivity. Attendance is built for this.

**When you check in with no signal:**

1. The app tries the request first — nothing is parked unnecessarily
2. If the network fails, the event is saved **on your device** with a unique
   event id, along with your compressed selfie
3. The screen shows it under **Pending sync**
4. Tap **Sync now** whenever you have signal (the app does not sync in the
   background — it sends when you tell it to, oldest first)
5. On success it disappears from the list

**If sync fails or is rejected:**

The record shows the reason, for example:

> *"You are 412 m from Whitefield Yard; the allowed radius is 100 m."*

It stays in the list marked **Failed** so you can read what happened, retry
after moving, or show it to HR. Nothing is silently discarded.

**What is *not* queued:** a refusal — a `403`, a duplicate, or an invalid
photo. Those are shown straight away, because asking again will not turn a
no into a yes.

> **Important:** offline records are re-validated by the server exactly as if
> they had arrived live — the site assignment, the geofence and the accuracy
> ceiling are all checked again. An altered device clock or a moved position
> does not become acceptable by being sent later. Retrying sends the *same*
> event id, so a lost reply can never become a second attendance record.

---

## 15. Permissions — what you will and will not see

The menu you see is built from your role's permissions. For example:

| Role | Sees |
|---|---|
| Employee | Own attendance, leave, payslips, documents |
| Site Supervisor | Plus own site's team, reports, approvals |
| Project Manager | Plus projects, sites, workforce, overtime approvals |
| HR | Plus all employees, attendance, leave, documents, assignments |
| Payroll | Plus payroll runs, payslips, loans |
| Finance | Plus expenses, loan deductions |
| Management | Dashboards and reports |
| Super Admin | Everything including settings and audit logs |

If a function you expect is missing, it likely means your role does not have that
permission — contact your administrator.

---

## 16. Help & Troubleshooting

| Problem | What to try |
|---|---|
| "Location not detected" | Ensure GPS is on and permission granted; move away from tall structures |
| "Your location is too inaccurate" | Move into the open; the ceiling is a configured value, not a guess |
| "Outside the allowed area" | Confirm you are at your assigned site; contact HR if the site is wrong |
| "Turn on location services" | Your permission is fine — the phone's Location switch is off |
| "You chose not to share location" | Open this app's Settings → Location → **Allow only while using the app** |
| Camera permission denied | Enable camera access in system settings for this app |
| "No camera available" | The device has no front-facing camera (or it is in use) — ask HR to record it another way |
| "You have already checked in today" | One row per day; check out, or contact HR if the row is wrong |
| "Session expired" | Log in again |
| "No connection" | Your record is queued on the device — tap **Sync now** when you have signal |
| Sync shows a reason | Read it; it is shown and kept, not discarded. Move, fix the cause, then retry |
| Notification not arriving | Check notification preferences and device notification settings |

---

## 17. Guide Status

| Section | Phase | Status |
|---|---|---|
| Foundation & documentation | 1 | ✅ Written |
| Authentication | 3 | ✅ |
| Employee / Project / Site management | 4 | ✅ |
| Attendance + geofence + selfie | 5 | ✅ |
| Offline sync | 5 | ✅ (manual **Sync now**; automatic background sync not built) |
| Site visits & daily movement timeline | 5 | ✅ |
| Site activity reports & PDF export | 7 | ⬜ |
| Shifts, timesheets, overtime | 8 | ⬜ |
| Leave & LOP | 9 | ⬜ |
| Documents, training, assets | 10 | ⬜ |
| Payroll, loans, expenses | 11 | ⬜ |
| Notifications, dashboards, reports | 12 | ⬜ |
