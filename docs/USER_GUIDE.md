# User Guide

> **Status:** Phase 1 — Foundation. **The application does not exist yet.**
> This guide describes the workflows each role will have once the corresponding phase
> ships. Sections are marked with the phase that delivers them.

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

> ✅ Phase 3 — sign-in, session restore and sign-out. Forgot-password arrives with the UI in Phase 4+.

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
from *Settings → Sessions* (Phase 4 UI); changing your password from any device
immediately signs out every other one.

**Forgot password:** *not available yet.* The API exists but deliberately
answers "not available" until a mail server can actually deliver the link —
contact your administrator for a reset in the meantime.

**Security:** your password is never stored in plain text, and your session token is kept
in your device's secure key storage. If you suspect any issue, sign out — this revokes
your token immediately.

---

## 3. Employee

### 3.1 Checking in ⬜ Phase 5

Attendance is designed to take **one tap** — the screen is deliberately simple because
it is used outdoors on a construction site.

```
Today's Attendance

Current Site:  Project A - Site 1

GPS:
  ✓ Location detected
  ✓ Within allowed area

        [ CHECK IN ]
```

**What happens:**

1. Open **Attendance**
2. The app shows your currently assigned site
3. Allow location permission when asked
4. Wait for the location check — you will see whether you are within the allowed area
5. The front camera opens → take a selfie
6. Preview it → **Retake** if needed, or confirm
7. Tap **Check In**

**After check-in:**

```
Checked in:  08:05 AM
Working:     04h 25m

[ SITE VISIT ]   [ CHECK OUT ]
```

**If you are outside the allowed area:**

> *"You are 482 m from the site. Maximum allowed is 100 m."*

No attendance is recorded. If you believe this is wrong (GPS drift, wrong site
assignment), contact your supervisor or HR — an authorized person can override it, and
every override is logged.

**Check-out works the same way** — you may check out at a *different* site from where
you started.

### 3.2 Working between sites ⬜ Phase 7

If you move to another site during the workday, record it so your time is attributed
correctly:

```
08:05   Check-in          Site A
10:30   Site visit start  Site B
12:15   Site visit end    Site B
14:00   Site visit start  Site C
17:45   Check-out         Site C
```

1. Tap **Site Visit**
2. Select the site and (optionally) the purpose
3. Location is captured automatically
4. When you leave, tap **End Visit**

Your daily timeline shows the full movement history.

> **Note:** the app does **not** track you continuously in the background. Location is
> captured only when you perform one of these specific actions.

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

> ⬜ Phase 7–8

| Capability | Detail |
|---|---|
| **Daily site report** | Workforce categories, total manpower, work planned/completed, materials, equipment, safety observations, delays, issues, photos, remarks |
| **Approve site activity reports** | Review reports from your team |
| **Create / approve overtime** | First step in the overtime approval chain |
| **Timesheet approval** | Verify hours for your team |
| **View your site's attendance** | Who is on site today |

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

> ⬜ Phase 4 / 12

**Dashboard:**
- Projects and sites
- Workforce per site
- Attendance overview
- Site visits and site reports
- Overtime requests awaiting approval

**Manage:**
- Project details and status (Planned / Active / On Hold / Completed / Cancelled)
- Sites within your projects, including geofence radius
- Approval of overtime for your projects

> Scope is limited to projects assigned to you.

---

## 7. HR Executive

> ⬜ Phase 4

- Create and edit employee records
- Manage departments and designations
- Upload and organize employee documents
- Track document expiry (passport, visa, Emirates ID, certificates)
- Run onboarding and see missing documents
- View attendance across the company
- First-level leave approval (depending on configured workflow)

---

## 8. HR Admin

> ⬜ Phase 4 / 5 / 9

Everything an HR Executive can do, plus:

| Capability | Detail |
|---|---|
| **Attendance management** | View all employees, dates, times, projects, sites, GPS, selfies and status |
| **Attendance override** | Correct an out-of-geofence or missing record — **reason required, audit-logged** |
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

> ⬜ Phase 3–4

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

1. The record is saved **on your device**
2. Your selfie is stored securely on your device
3. The screen shows **"Pending Sync"**
4. When connection returns (or you next open the app), it syncs automatically
5. The status changes to **Synced**

**If sync fails or is rejected:**

The record shows **"Rejected"** with the reason, for example:

> *"Outside the allowed area for this site."*

Nothing is silently discarded, and you can retry or contact HR.

> **Important:** offline records are re-validated by the server. A device clock or
> location that has been altered will not be accepted.

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
| "Outside the allowed area" | Confirm you are at your assigned site; contact HR if the site is wrong |
| Camera permission denied | Enable camera access in system settings for this app |
| "Session expired" | Log in again |
| "No connection" | Your record is saved locally and will sync automatically |
| Sync shows "Rejected" | Read the reason shown; contact HR if it appears incorrect |
| Notification not arriving | Check notification preferences and device notification settings |

---

## 17. Guide Status

| Section | Phase | Status |
|---|---|---|
| Foundation & documentation | 1 | ✅ Written |
| Authentication | 3 | ⬜ |
| Employee / Project / Site management | 4 | ⬜ |
| Attendance + geofence + selfie | 5 | ⬜ |
| Offline sync | 6 | ⬜ |
| Site visits & reports | 7 | ⬜ |
| Shifts, timesheets, overtime | 8 | ⬜ |
| Leave & LOP | 9 | ⬜ |
| Documents, training, assets | 10 | ⬜ |
| Payroll, loans, expenses | 11 | ⬜ |
| Notifications, dashboards, reports | 12 | ⬜ |
