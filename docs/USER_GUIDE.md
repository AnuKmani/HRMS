# User Guide

> **Status:** Phase 9 — sign-in, the organisation screens, GPS attendance with
> site visits, **leave, the holiday calendar, timesheets and overtime**, the
> **site activity reports and the official daily site report (with its PDF)**,
> **payroll: the ledger, salary slips, loans and salary certificates**, and now
> **expense claims: filling one in, the receipt from the camera, the two-step
> approval chain and the filters that find an old claim** exist and are usable.
> This guide describes the workflows each role will have once the corresponding
> phase ships. Sections are marked with the phase that delivers them; ✅ means
> it is in the app today.

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
open — Employees, Departments, Designations, Projects, Sites (Phase 4),
Attendance (Phase 5), Leave, Timesheets, Overtime and Holidays (Phase 6),
since **Phase 7** **Site activity** and **Daily site reports**, and since
**Phase 8** **Payroll**, **Salary slips**, **Loans** and **Salary
certificates** — and nothing else. A role that cannot open a module is not
shown a door it would be refused behind. **Holidays is the one tile drawn
for everyone**: reading the calendar is not a privilege anyone grants you.

The two report tiles are gated separately on purpose: `Site activity` is
drawn for anyone who may file their own, while `Daily site reports` is not
drawn for an Employee at all — the official record about a site-day belongs
to the people who run the site.

The four money tiles are gated separately for the same reason, and they are
**four different questions**:

| Tile | Means |
|---|---|
| **Payroll** | "the ledger" — drawn for `payroll.view`, or for `payroll.summary.view` alone (Management and Finance see the totals and no rows) |
| **Salary slips** | "give me my payslip" — drawn for `salary_slips.view`, which is a *separate* grant: a role may hold it without holding the payroll module |
| **Loans** | "what I have borrowed, and what is left" — `loans.view`; asking for one needs `loans.create` |
| **Salary certificates** | "ask for a letter about my employment" — `salary_certificates.view`, the same grant that reads them; *deciding* one is `.manage` |

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

### 3.3 Site activity report ✅ Phase 7

Your own account of what happened on a site today. It is yours: only you and
the people responsible for that site can see it, and nobody has to approve
it.

1. Open **Site activity** from the home screen (or **File a site report**)
2. Tap **File a site report**
3. Pick the **site** — the project fills in from it, because a site belongs
   to one project
4. Set the **date** (today is offered; a future date is refused)
5. Choose a **work category** and describe **what was done**
6. Slide **progress** to the completed percentage
7. Optionally add **manpower**, **materials used** and **equipment used**
8. Record anything that went wrong under **Issues**, and anything unsafe
   under **Safety** — these are plain notes, one block each
9. Add **photos** with the camera (up to 12 for the whole report)
10. Tap **Take reading** for the location — the phone shows you the accuracy
    it got before you commit to it
11. **Save** as a draft, or **Submit** to file it

**Drafts vs filed.** A draft is yours to edit and delete. Once submitted it
is filed and read-only: editing it afterwards answers *"This report has
already been filed…"* rather than quietly reopening it. Submitting is the
only moment the location reading is required, and the reading is taken then
— a fix saved earlier on the form is not reused, because five minutes is a
long time on a site.

**Losing your work.** The form keeps a local copy of what you typed while you
are still creating the report, so leaving and coming back brings it back
labelled *"Local draft"*. That label is honest: the report has **not** been
sent to the server yet — **Saved** on the server means the row exists and
will list for everyone who can see it. Photos are held on the phone until the
report itself exists, then sent as one batch; if that batch fails, the report
is kept and the frames are not, and the app says exactly that. Filing this
report never blocks or delays your attendance.

### 3.4 Applying for leave ✅ Phase 6

1. Open **Leave**
2. Check your balance — entitlement, used, pending, remaining (the
   **Balances** action in the app bar)
3. Tap **Apply**
4. Choose leave type, dates and reason — the number of days is calculated by
   the server from weekends and the holiday calendar, so the figure you see
   is the figure that will be reserved
5. Save as a **draft**, or **Submit** to start the approval chain

**Statuses you will see:**

| Status | Meaning |
|---|---|
| Draft | Saved, not submitted — only you can edit or delete it |
| Pending | Waiting for approval; the chain shows who has it now |
| Approved | Accepted |
| Rejected | Declined, with the approver's reason |
| Cancelled | Withdrawn by you — the days go back into your balance |
| LOP | Loss of pay — e.g. a sick certificate was not submitted in time |

**While it is a draft:** Edit, Submit and Cancel.
**While it is pending and it is yours:** Cancel only — you cannot approve your
own request, even if you are also a manager.
**While it is pending and you are the approver at the current step:** Approve
or Reject. Reject asks for a reason. Approving one link in the chain does not
approve the request — the chain simply moves to the next person, and they
will find it waiting when they open their queue.

**Sick leave and the certificate.** If the leave type requires a medical
certificate, the screen shows the deadline (default **2 days** after your last
day) and a **Photograph and file** button that opens the **back camera** so you
can capture the document directly — no file manager, no extra app. The
original scan is kept as-is and is readable only by you, your approver and HR.
Miss the deadline and the request becomes **LOP** automatically, server-side,
with the reason recorded and the days released back to the paid leave type.
You can see it happen under `Leave → status: LOP`.

### 3.5 Timesheet, overtime & holidays ✅ Phase 6

**Timesheets** are built for you from your attendance — there is nothing to
fill in:

- View your recorded working hours per day, with overtime shown separately
- Each day is `open`, `complete` or `incomplete` — a description of the day,
  not something you sign off
- Managers with `timesheets.manage` can regenerate a window from attendance
  after a correction; doing it twice refreshes rather than duplicates

**Overtime:**

- Claim extra hours with a date, minutes and a reason
- Track the approval chain: *Supervisor → Project Manager → HR / Payroll*
- Only **approved** overtime is marked payroll-eligible — a rejected or
  cancelled claim is never worth anything, whatever was typed
- You may claim only for a day that has already happened

**Holidays** are readable by everyone: public days, company days and the days
your own site is closed. Only HR can add or retire one — there is no delete,
because a day the year's leave maths already used cannot be quietly erased.

### 3.6 Expenses ✅ Phase 9

An **expense claim** is money you have already spent on the company's behalf —
a taxi to the site, materials bought on the road, a meal during a delivery —
filed with the date, the reason and the **receipt** that proves it. Nothing is
reimbursed until two people have signed it off: **your supervisor**, then
**Finance / HR**. Writing the claim is yours; deciding it is theirs.

**Filling one in:**

1. Open **Expenses** on the home screen (the door is drawn for `expenses.view`)
   → tap **+**
2. **Date spent** — the money has gone out already, so a future date is refused
3. **Category** — chosen from the list your company has set up. The moment you
   pick one, its rules appear in a line under the picker: *Needs a receipt*,
   *Up to 1000.00*. That line is read from the category itself, so it is the
   rule rather than a copy of it that could drift
4. **Amount** and **Currency** — a plain figure and the three-letter code it
   was charged in; the currency starts at `INR`
5. **What it was for** — required, in a sentence an approver can act on
6. **Site** and **Project** — both optional; if you pick a site, the form
   reminds you the site has to belong to the project you chose
7. **Save as draft** — you land straight on the claim you just made

There is deliberately no *status*, no *approval step* and no *employee* field.
A new claim is always a draft, the workflow decides who signs it, and it is
filed for whoever is signed in — you are never asked to declare your own name
or where in a process you have not started.

**Attaching the receipt.** On the claim, tap **Photograph a receipt**: the
**back** camera opens, you take the slip, and the frame is sent as it was
taken — no file manager, no second app. Receipts can be added and removed
**only while the claim is a draft**. Tapping one opens it — a photograph in a
picture, a PDF in your phone's viewer. There is no link to copy or forward: a
receipt is opened by its id, through the same permission that already governs
the claim. If the category needs a receipt and there is none, the claim says
so on the screen while you are still filling it in.

**Submitting.** **Submit** starts the approval chain, and from that moment the
claim is no longer editable — the chain, not the form, owns what happens next.

**Watching the chain.** The claim draws its two steps, in order:

1. **Your supervisor** — the line manager the claim is routed to
2. **Finance / HR** — the back office that pays

Approving one step does **not** approve the claim: it only moves the chain to
the next person, who finds it waiting in their queue.

**What the approver sees.** The amount in figures, the date, the category,
whose claim it is, the site and project, what it was for, how far the chain
has come, and the receipts. Reading *somebody else's* receipts is a separate
permission (`expenses.receipts.view`): a role can be shown the numbers on a
claim without being handed every invoice behind it. Two buttons, each asking
for a confirmation first:

| Button | What it does |
|---|---|
| **Approve** | Signs your link of the chain and passes it on. Remarks optional |
| **Reject** | Ends the claim. **Remarks are required** — the dialog will not let you refuse without saying why, and the claimant reads them |

After the answer the screen re-reads the claim from the server, so the status,
the chain and the receipt count you then see are the outcome, not what the
button intended. **Nobody approves their own claim**, whatever their role
permits elsewhere.

**Cancelling.** A draft is yours to throw away, and a claim still waiting can
be withdrawn — it leaves the chain and no money moves. While a claim is
waiting *and it is yours to answer*, you are offered **Approve** / **Reject**
instead of a cancel: the person holding the decision decides, they do not
withdraw it. Once a claim is **Approved** or **Rejected**, the answer stands —
there is no un-decide, the same as leave.

**Statuses you will see:**

| Status | Meaning |
|---|---|
| Draft | Saved, not submitted — yours to correct, add receipts to, or cancel |
| Awaiting approval | In the chain; the claim shows who has it now |
| Approved | Both steps signed |
| Rejected | Refused, with the remarks that say why |
| Cancelled | Withdrawn by you before anyone decided |

**Finding an old claim.** The list shows every claim you may read — yours, and
those your role is allowed to open — as the date and amount, the category,
the person, the site or project, the status, and how many receipts are
attached. The **Status** filter narrows it to *All statuses / Draft / Awaiting
approval / Approved / Rejected / Cancelled*; pull to refresh, and **Load more**
past the first page. The filter is a question put to the server rather than a
trim of the rows in front of you, so the *remaining* count behind **Load more**
describes the filter and not the page you happened to scroll to.

> **Not built yet in this module (Phase 9):**
>
> - **No expense-category screen.** The app *reads* the categories your company
>   has seeded — you may pick one, but nobody can add, rename or retire a
>   category from the app.
> - **Camera JPEG only.** A receipt is photographed as a JPEG. The app cannot
>   capture or produce a PDF; a receipt that already exists as a PDF can be
>   stored and opened, but not made here.
> - **No currency conversion.** The amount is claimed in the currency typed —
>   nothing converts it into another.
> - **`INR` is a starting value, not a setting.** There is no settings screen
>   to read your organisation's default currency from, so the field starts at
>   `INR` and you change it per claim.
> - **No notifications.** Nothing tells you that a claim is waiting, was
>   approved or was refused (Phase 11); open the list to see where it stands.
> - **No audit trail rows for expense decisions.** The chain and the remarks
>   are the record of who said what; a separate audit-log entry per decision is
>   not written yet.

### 3.7 Payslips, loans & salary certificates ✅ Phase 8

**Your salary slip.** Open **Salary slips** → tap a month. The list shows
what is left and to whom; the slip itself is itemised — basic, allowances,
overtime, LOP, unpaid leave, loan deduction, net — with the working it was
computed from, not just a total. **Download** renders the PDF on the spot
and hands it to your phone's viewer. Nothing is stored anywhere: there is no
link to forward, only the app and the API route behind your own session.

**Ask for an advance.** Open **Loans** → **Ask for a loan**, pick the kind
(salary advance or loan), the amount, the number of installments and the
start date. You are never asked to look up your own employee id — the
server knows who is asking. A submitted loan goes to whoever may approve it;
you may withdraw it until they answer. Once approved, the schedule is fixed
and each pay run takes what it can of the next payment due, marked against
that run so the same payment can never be taken twice.

**When a month cannot take all of it.** Payroll never lets a repayment take
your net salary below the **minimum net salary** rule (`0` by default —
"never pay a negative salary"). If the installment due is larger than what
is left above that floor, the run takes only the part that fits and the
loan screen keeps three figures apart: **Amount** (what the schedule says
is due), **Taken** (what runs have actually taken of it) and what is still
owed on it — shown as **Partly deducted**. Nothing is written off. The
remainder is the first thing the next run takes, together with anything an
earlier month had to leave behind, oldest payment first, and your **balance
only ever falls by what was really deducted** — never by what was merely
scheduled.

**Ask for a certificate.** Open **Salary certificates** → **Request**, give
the purpose and the date, and send. HR or Payroll Admin decides it; when it
is issued you can open it and export the PDF. You can follow it through
*Awaiting → Approved → Issued* and cancel it while it is still yours to
cancel.

> You can only ever see your own salary information. An Employee's `payroll`
> tile opens **one row — yours**. Nobody's payslip is a URL: the document is
> rendered for your session each time you ask.

> **Your own documents** (passport, visa, Emirates ID, contracts) are still
> ⬜ Phase 10 — that is a different module from payslips.

### 3.8 Dashboard ⬜ Phase 11

- Today's attendance and current site
- Check-in / check-out times and working hours
- Leave balance and recent requests
- Latest salary slip
- Site visits
- Pending actions

---

## 4. Site Supervisor

> ✅ Phase 5 (attendance viewing) · ✅ Phase 6 (overtime approval) · ✅ Phase 7 (site reports & the official daily report)

| Capability | Detail |
|---|---|
| **File your own site activity reports** ✅ | What you did on a site today — free text, progress %, photos, GPS at submit |
| **Read the activity reports for the sites you run** ✅ | Your team's, not the company's. A person's own report is always visible to its author |
| **Daily site report** ✅ | Workforce categories, total manpower, work planned/completed, materials, equipment, safety observations, delays, issues, photos, remarks — **one official report per site per day** |
| **Approve site activity reports** | ⬜ Not built: Phase 7 files and stops; there is no approve step yet |
| **Approve overtime** ✅ | First step in the overtime approval chain — only while your report has it waiting, never your own claim |
| **Read your team's timesheets** ✅ | Working days and hours for the people and sites you run. There is no timesheet to approve: the row is derived from attendance |
| **Approve leave** ✅ | If the standard chain names you as the supervisor step |
| **View your site's attendance** ✅ | Who was on site, and when — restricted to the sites you run. You also see visits to your sites, and you cannot read a day recorded anywhere else |

**Filing the official daily site report ✅:**

1. Open **Daily site reports** from the home screen
2. Tap **Prepare today's report**
3. Pick the site and the date. If somebody has already filed that site-day,
   the date field says so and will not accept the same pair again —
   *one official document per site per day*
4. Add **workforce categories** as rows: name the category the way this site
   does (`Carpenters`, `Electricians`, `Helpers` …) and its count. The
   **total is worked out from the rows** — you do not type it
5. Describe what was **planned** and what was **completed**
6. Add **materials used** and **equipment on site** as rows — name,
   quantity, unit; operating hours and condition for plant. These are facts
   about today's work, not an inventory and not an asset register
7. Note **safety observations**, **delays** and **issues**, plus remarks
8. Attach **photos** (up to 12), then **Save** (draft) or **Submit**

**Exporting to PDF ✅.** Open the report and tap **Download PDF**. The
document is prepared by the server from the row as it stands *now* —
heading with your company name, project, site, date, prepared-by, the
workforce summary, work planned/completed, materials, equipment, safety,
delays, issues, remarks, the photographs and a timestamp with a reference
number. While it is being prepared the button says *Preparing…* and is
disabled so it cannot be tapped twice. Nothing is stored as a file, so
there is no link to share: the document comes through you, and only while
you are signed in with `daily_site_reports.pdf`.

---

## 5. Site Engineer

> ✅ Phase 6 (own leave, balances, timesheets, overtime) · ✅ Phase 7 (site reports)

Similar to Site Supervisor, focused on technical reporting:

- File site activity reports with progress percentages ✅ — for the sites
  you are assigned to, with photos and a location reading at submit
- Record materials, manpower and equipment ✅ (free text on your own
  activity report; rows on the official daily report)
- Flag issues and safety concerns ✅
- Read and prepare the **official daily site report** for the sites you run,
  and download it as PDF ✅
- View site assignments for your project
- Apply for leave and watch it move through the chain ✅
- See your own working days and claim overtime ✅ — but **not** approve
  either: this role holds no `leave.approve` or `overtime.approve`, so even
  if a workflow were pointed at it, the route would refuse first

---

## 6. Project Manager

> ✅ Phase 4 (project & site management) · ✅ Phase 5 (attendance & visits on your projects) · ⬜ Phase 11 (dashboard)

**Dashboard:**
- Projects and sites
- Workforce per site
- Attendance overview
- Site visits and site reports
- Overtime requests awaiting approval

**Manage (live today):**
- Project details and status (Planned / Active / On Hold / Completed / Cancelled)
- Sites within your projects, including geofence radius
- Approval of overtime for your projects ✅ — and of leave where the chain
  names you as the step
- **Attendance on the projects you manage** — every day recorded on your
  projects and their sites, with the GPS distance and the photograph where
  one was taken. A project's own days and nobody else's
- **Site reports across your projects** ✅ — every activity report and every
  official daily report filed on a site belonging to a project you manage,
  with the photographs and the PDF export. `daily_site_reports.manage` is
  yours alone among the business roles

> Scope is limited to projects assigned to you — the server narrows your list,
> so you are not shown projects you do not run. Asking for a colleague's
> record by id is refused with `403`, whatever the filter says.

---

## 7. HR Executive

> ✅ Phase 4 (employees, departments, designations) · ✅ Phase 5 (attendance viewing) · ✅ Phase 6 (leave approval) · ⬜ for documents

- Create and edit employee records
- Manage departments and designations
- Upload and organize employee documents
- Track document expiry (passport, visa, Emirates ID, certificates)
- Run onboarding and see missing documents
- View attendance across the company — every employee, every day, with the
  GPS distance and the photograph, filtered by date, project, site or status
- Leave approval (depending on configured workflow) — HR Admin is the last
  step of the standard chain
- Read leave balances for the people you may already see

HR Executive may maintain the roster but **cannot delete an employee and cannot
see anyone's salary** — those two are separate permissions held elsewhere.

---

## 8. HR Admin

> ✅ Phase 4 (employee management, site assignments) · ✅ Phase 5 (attendance viewing + configuration) · ✅ Phase 6 (leave types, balances, holidays, approval workflows)

Everything an HR Executive can do, plus:

| Capability | Detail |
|---|---|
| **Attendance management** ✅ | View all employees, dates, times, projects, sites, GPS, selfies and status — filtered and paginated |
| **Configure geofence radius** ✅ | Per site, no code changes needed |
| **Configure working rules** ✅ | Start time, grace period, break, minimum hours, overtime threshold — read from settings, never hard-coded |
| **Attendance override** | ⬜ **Not built yet.** Correcting an out-of-geofence or missing record is planned with a mandatory reason and an audit trail. Today the correct route is for the employee to re-check-in, or for you to note it with the record; nothing silently overwrites what happened |
| **Configure geofence radius** | Per site, no code changes needed |
| **Configure working rules** | Start time, grace period, minimum hours, overtime threshold |
| **Leave approval & configuration** ✅ | Leave types (paid or not, entitlement, carry-forward, per-request maximum, certificate requirement and deadline, negative balance allowed), the approval workflow each type uses, hand-correction of a balance, and reading every balance |
| **Holiday calendars** ✅ | Public, company and site-specific days — add and retire by status (there is no delete) |
| **Approval workflows** ✅ | Who must sign off what: supervisor, a named role, or anyone holding a permission. One default per subject (leave, overtime) |
| **Shift management** | General, Morning, Evening, Night, Custom |
| **Site assignments** | Assign temporarily or permanently — history preserved |
| **Employee management** | Full CRUD with status changes |
| **Reports** | Attendance, leave, manpower, document expiry, movement |

---

## 9. Payroll Admin

> ✅ Phase 8 (the whole payroll family) · ✅ Phase 6 (reads the inputs a run needs)

**The monthly run:**

1. Open **Payroll**, choose the year and month (it opens on the current one)
2. Press **Run payroll** and confirm. Every employee is priced from
   approved attendance, approved **and** `payroll_eligible` overtime, LOP
   days, approved allowances, approved adjustments and the part of each
   loan or salary-advance installment due — including anything an earlier
   run had to leave behind — that fits above the minimum net salary floor
3. Each row lands on **Calculated**. A row whose employee has no salary on
   record stays a **Draft** and says so — it is never shown as a zero
4. **Review** the rows that are right, **Process** them, and **Lock** the
   ones you are finished with
5. **Locking is final.** A locked month cannot be recalculated, edited or
   reopened — that is the point of it, and it is a permission of its own
   (`payroll.lock`) that only this role holds. Its loan deductions are
   frozen with it: changing the minimum-net-salary setting afterwards
   cannot re-cut a figure somebody has already been paid from

Running the same month twice is safe: rows already decided are left exactly
as they are and the run reports how many it calculated, how many were new
and how many it skipped. **Nothing here is ever deleted** — a correction is a
new entry with its own sign, so the original figure still stands.

**Also yours:** allowances and payroll adjustments (approve or reject them
before a run reads them), the salary-slip list and its PDFs, salary
certificate requests (approve, issue, cancel), and loans (approve, reject,
manage).

**Payroll only uses approved data** — unapproved overtime or leave never
enters a run. The two facts a run reads are maintained by Phase 6:
`overtime_requests.payroll_eligible` (true only when a claim completed its
chain) and `leave_requests.lop_days`; Phase 8 prices both into the slip and
writes neither back.

> **Before production:** the overtime rate is a configurable multiplier
> (default ×1.5 on the hourly rate derived from the LOP divisor, 30 by
> default). It is a **generic engine setting, not a statutory rate** — the
> UAE Labour Law figures your deployment must use have not been wired in and
> must be validated against your own jurisdiction first.

> **The net-salary floor is a setting too.** `payroll.minimum_net_salary`
> (default `0`) is the lowest net a run will pay. It caps **repayments
> only** — loss of pay and approved adjustments are facts about the month
> and are never rewritten to protect it. Any statutory floor your
> jurisdiction imposes (the UAE's Wage Protection System, for one) must be
> entered deliberately after validation; none is assumed for you here.

---

## 10. Finance

> ✅ Phase 8 (payroll summary, salary slips, loans — read-only) · ✅ Phase 9 (expenses) · ✅ Phase 6 (payroll-preparation reads)

- **Read the payroll summary** for any period — counts, gross, deductions,
  net. The summary has **no names in it**: this role sees the shape of a
  month, not the ledger behind it ✅ Phase 8
- **Read and export salary slips** (`salary_slips.view` + `.manage`) ✅ Phase 8
- **Read loans** — who owes what and what is left ✅ Phase 8
- Review and approve **expenses** ✅ Phase 9
- Verify receipts ✅ Phase 9
- Access financial reports (payroll summary, expense report)
- Read **leave balances, working days and approved overtime** without
  approving any of them — the view a payroll preparation needs ✅
- No access to HR-only functions: this role holds no `leave.create`,
  `leave.approve`, `holidays.manage`, `overtime.approve` — **and no
  `payroll.process` or `payroll.lock`: Finance reads the month, it does not
  run or freeze it**

---

## 11. Management

> ⬜ Phase 11 (dashboards) · ✅ Phase 6 (leave and overtime approval) · ✅ Phase 8 (payroll summary)

**Dashboard:**
- Total workforce
- Site manpower distribution
- Attendance trends
- Leave trends
- Overtime totals
- Payroll summary ✅ Phase 8 — `GET /payroll/summary` gives counts and totals
  for a month, and **deliberately no rows**: this role is offered the money
  as a figure, never as a list of people
- Project workforce breakdown

Read-only — plus the ability to view any report, plus **approve leave and
overtime** where a configured workflow names this role as the current step ✅.
Management may open the **Payroll** door for the summary and is **not**
offered the salary-slip or ledger views.

---

## 12. Super Admin

> ✅ Phase 4 (every module screen, through `*` permissions) · ✅ Phase 6 (leave types, balances, holidays, approval workflows, timesheets, overtime) · ⬜ roles / users / settings UI

- Full system access
- Manage roles and permissions
- Manage users and sessions
- Configure all system settings
- View **audit logs**
- Manage notification preferences and templates

> Super Admin should be used sparingly. Day-to-day administration belongs with HR Admin.

---

## 13. Notifications

> ⬜ Phase 11 — **none of the rows below are delivered today.**

**Nothing in this application sends a notification yet.** No push (no FCM), no
email, no in-app bell. Phase 6 added *hooks* rather than delivery: an LOP
conversion dispatches a `LeaveConvertedToLop` event after commit, and an
approval writes the next `approval_records` step — so the facts a
notification would be built from are durable and queryable, but nothing is
sent, and the "who receives it" column is a contract for the notifications
phase rather than a description of behaviour.

Until then, a pending request is visible to its approver by opening their
queue, and a sick-leave deadline is visible on the request itself.

| Event | Who receives it (planned) |
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

Notification preferences can be configured per user (⬜ with this phase).

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

**Reports are a different story, and deliberately so.**

A report is not queued. While you are *creating* one, the form keeps a local
copy so a tap on Back or a crash does not lose what you typed — that copy is
labelled **Local draft** and means "on this phone only". Pressing **Save**
sends it to the server, and only then does it appear in the list for
everybody. If you are offline when you press Save, the save fails and says
so; the local draft stays for you to retry later.

The photographs ride the same way: they are held on the phone until the
report itself has been created, then sent as one batch. If that batch
fails, the report is already on the server and the frames are not — the
app tells you which is which.

Filing a report never enters the attendance sync queue, and the queue never
sends a report. The two flows do not touch.

---

## 15. Permissions — what you will and will not see

The menu you see is built from your role's permissions. For example:

| Role | Sees |
|---|---|
| Employee | Own attendance, own leave requests and balances, own timesheets, own overtime claims, the holiday calendar, **their own site activity reports**, and from Phase 8 **their own payslip, their own loans and their own certificate requests**. The official daily report, anyone else's pay and the payroll ledger are **not** in an Employee's app |
| Site Supervisor | Plus their site's team, reports, the official daily report and its PDF for the sites they run, overtime approval (their step), leave approval (their step), and loans they may ask for. **No payroll at all** — this role is not offered the Payroll or Salary slips door |
| Site Engineer | Plus site activity reports and the official daily report for their sites — prepare, edit, submit, export; loans they may ask for; **no payroll** |
| Project Manager | Plus projects, sites, workforce, overtime approvals, leave approvals, every report on the projects they manage (`daily_site_reports.manage`), and loans; **no payroll** |
| HR | Plus all employees, attendance, leave, balances, holiday calendar editing, workflow configuration — and the whole payroll family except **locking**: HR Admin runs, reviews, finalises, approves loans and decides certificates |
| Payroll Admin | **The payroll door**: run, review, finalise and **lock** the month, allowances, adjustments, salary slips, certificate decisions, loans |
| Finance | The payroll **summary** (counts and totals, no names), salary slips and loans — read only; no run, no lock |
| Management | The payroll **summary** through the Payroll door, and no rows behind it |
| Super Admin | Everything |

**Expense permissions ✅ Phase 9 — and who holds each:**

| Permission | What it lets you do | Held by |
|---|---|---|
| `expenses.view` | Open **Expenses**: the list, a claim, and the home door | **10 roles** — Employee, Finance, HR Admin, HR Executive, Management, Payroll Admin, Project Manager, Site Engineer, Site Supervisor, Super Admin |
| `expenses.create` | Raise a claim, submit it, cancel your own | **7 roles** — Employee, HR Admin, HR Executive, Project Manager, Site Engineer, Site Supervisor, Super Admin |
| `expenses.update` | Correct a draft | **9 roles** — every `expenses.view` role except Management: Employee, Finance, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Engineer, Site Supervisor, Super Admin |
| `expenses.approve` | Approve or reject somebody else's claim | **7 roles** — Finance, HR Admin, HR Executive, Payroll Admin, Project Manager, Site Supervisor, Super Admin |
| `expenses.manage` | The back office — and the grant the chain's second link ("Finance / HR") resolves to | **4 roles** — Finance, HR Admin, Payroll Admin, Super Admin |
| `expenses.receipts.view` | Read the documents attached to a claim that is **not yours** | The same **7** as `expenses.approve` |

**Reading money, and whose (✅ Phase 9):**

- **Your own claims are always yours to read.** `expenses.view` is the coarse
  door; *whose* claims appear behind it is the server's answer, not the app's —
  you read yours, a supervisor reads the claims routed to them, and Finance,
  HR and Payroll read the ones their scope covers.
- **Evidence is a separate grant from the figures.** Your own receipts need
  only `expenses.view`; somebody else's need `expenses.receipts.view`, which
  Site Engineer deliberately does not hold — a role can be shown the numbers
  on a claim without being handed every invoice behind it.
- **Approving is a grant of its own.** `expenses.approve` is never implied by
  `expenses.create` — the seed gives the two to different roles, so filing a
  claim never arrives with the power to answer it.
- **Filing and correcting are two different doors.** Payroll Admin holds
  `expenses.update` but not `expenses.create` — the back office may correct a
  line of spend it finds, not open a new one.
- **Management holds the door and no decisions.** Among the six grants above
  it holds `expenses.view` alone: claims may be read, never edited or answered.

Four things worth knowing about the new modules:

- **Holidays need no permission to read.** Everyone sees the calendar;
  only `holidays.manage` can add or retire a day.
- **Timesheets are derived**, so there is nothing to approve. A manager who
  corrected attendance regenerates the window instead.
- **Nobody approves their own request** — not an ordinary employee, not a
  manager, not even a Super Admin who happens to be the requester.
- **Only the person the chain is currently waiting on may act.** An approver
  further down sees the request but cannot sign for the step before theirs.
- **Money only moves forward.** Payroll goes *Draft → Calculated → Reviewed →
  Processed → Locked* and never backwards; a loan's schedule is fixed when it
  is approved. What you can do is add a correction, not remove one.
- **Nobody decides their own money.** You cannot approve your own loan or
  sign off your own salary certificate, whatever you are permitted to do for
  other people's.

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
| "Attach the medical certificate." | The leave type requires one — use **Photograph and file** on the request |
| "That file is not a PDF or an image a reader could open." | The scan is unreadable or not really a PDF/JPG/PNG. Re-photograph the document in good light |
| "That certificate is too large." | Keep the scan under the configured limit (5 MB by default) |
| Leave shows **LOP** | The certificate deadline passed. The conversion is server-side and final for that request — apply again or ask HR |
| An approve button you cannot press | You are not the step the chain is waiting on, or it is your own request. Nobody approves their own |
| "A location reading is needed before this can be submitted." | Tap **Take reading** on the form, wait for the accuracy to settle, then submit again. A reading saved earlier on the form is deliberately not reused |
| "This report has already been filed and can no longer be edited." | Submitting is final. If the row is wrong, tell the person who manages the site — there is no un-submit, and there is no approve step yet either |
| "Another official report already exists for this site on this date." | Somebody has already filed that site-day. Open it from the list — one official report per site per day |
| "A report for that project and site does not match." | The site was picked from a project it does not belong to. Re-pick the site and the project follows |
| A photograph fails to upload | The report itself is saved. The app says which of the two happened; the frames are held on the phone so you can try the upload again |
| "Preparing…" never finishes | The server is slow or unreachable. The button stays disabled while it works so a second tap cannot start a second document; leave the screen if it will not finish |
| "You are not allowed to export this report." | Your role holds no `daily_site_reports.pdf`. Viewing the report and exporting it are separate permissions |
| A draft labelled **Local draft** | It is on this phone only and has not been sent. **Save** sends it; **Start over** throws it away |
| A payroll row says **No salary on record** | The employee has no salary configured, so the run could not price them. The row is a Draft and tells you so — it is never shown as `0.00` as though that were a salary |
| "Only a payroll that has been calculated can be reviewed." (409) | You asked for a step that is not the next one. Open the row and take the step it offers — the ladder only climbs |
| A month you expected to re-run did not change | It has already been decided. A second run reports *0 updated* rather than re-pricing rows somebody may already have been paid from |
| The **Run** button is not there | Running needs `payroll.process`, which is not the same grant as being able to read payroll |
| A payslip button that is not there | Viewing a payslip needs `salary_slips.view`, a grant of its own — ask your administrator; it is deliberately separate from payroll |
| "You cannot approve your own loan." (403) | Nobody approves their own, whatever they are allowed to approve for others. Ask a colleague with `loans.approve` |
| "Only a loan awaiting a decision can be approved." (409) | Somebody has already answered it — or you have already withdrawn it. Open it to see where it stands |
| A loan installment already taken | Each installment is taken by exactly one pay run and recorded against it. If a run is recalculated the installment is released first, then taken again — and only the share *that* run took is given back |
| An installment shows **Partly deducted** | The month could only take part of it without pushing net pay below the minimum net salary rule. The rest is carried forward automatically and is the first thing the next run takes; it is delayed, not cancelled, and the loan balance falls only by what was actually taken |
| A payment that fell due last month is only being taken now | The earlier run had no room above the floor, so the payment stayed outstanding rather than being skipped. Older payments are always collected before newer ones |
| Recalculating a month answers **409** | The row is already reviewed, processed or locked. Locked is the end of the road — the figures somebody may already have been paid from do not move |
| "Only an approved certificate can be issued." (409) | Approve it first; a refused certificate can never be issued |
| The payroll door opens with no rows | You hold `payroll.summary.view` only. That is the summary — counts and totals, and deliberately no list of people |

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
| **Site activity reports (own + your sites)** | **7** | ✅ |
| **Official daily site report + on-demand PDF** | **7** | ✅ |
| Shifts | 8 | ⬜ |
| **Leave, balances & sick-cert → LOP** | **6** | ✅ |
| **Timesheets & overtime** | **6** | ✅ |
| **Holiday calendar** | **6** | ✅ |
| **Payroll ledger, run & lock** | **8** | ✅ |
| **Salary slips (own, on demand)** | **8** | ✅ |
| **Loans & salary advances** | **8** | ✅ |
| **Salary certificate requests** | **8** | ✅ |
| Expenses | 9 | ✅ |
| Documents, training, assets | 10 | ⬜ |
| Notifications, dashboards, reports | 11 | ⬜ |
