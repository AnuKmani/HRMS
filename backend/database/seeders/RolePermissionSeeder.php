<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Role -> permission grants.
     *
     * `['*']` means "every permission" (Super Admin). Everything else is an
     * explicit allow-list: a permission a role does not appear in is denied.
     *
     * Permission is a *coarse gate* — "may this role touch employees at all?".
     * Row-level scope ("only their own attendance") is enforced separately by
     * Laravel policies, so an Employee holding `attendance.view` still only
     * reads their own records.
     *
     * @var array<string, array<int, string>|string>
     */
    public const MAP = [
        'Super Admin' => ['*'],

        'HR Admin' => [
            'dashboard.view',
            'employees.view', 'employees.create', 'employees.update', 'employees.delete',
            'employees.salary.view',
            'departments.view', 'departments.manage',
            'designations.view', 'designations.manage',
            'attendance.view', 'attendance.manage',
            'approvals.view', 'approvals.manage',
            'leave.view', 'leave.create', 'leave.approve', 'leave.manage',
            'leave.balance.view', 'leave.balance.manage',
            'holidays.manage',
            'timesheets.view', 'timesheets.manage',
            'overtime.view', 'overtime.create', 'overtime.approve', 'overtime.manage',
            'payroll.view', 'payroll.manage', 'payroll.process', 'payroll.summary.view',
            'salary_slips.view', 'salary_slips.manage',
            'salary_certificates.view', 'salary_certificates.manage',
            'loans.view', 'loans.create', 'loans.approve', 'loans.manage',
            'projects.view',
            'sites.view',
            'shifts.view', 'shifts.manage',
            'assignments.view', 'assignments.manage',
            'reports.view', 'reports.export',
            'site_activity_reports.view',
            'daily_site_reports.view', 'daily_site_reports.pdf',
            'documents.view', 'documents.create', 'documents.update',
            'documents.verify', 'documents.delete',
            'documents.expiry.view', 'documents.manage',
            'onboarding.view', 'onboarding.manage',
            // The whole of both new modules: HR owns the training
            // catalogue, puts people on courses, records who finished,
            // opens their certificates and reads the expiry report — and
            // is the master record's steward for company property, from
            // creating an asset to writing one off.
            'training.view', 'training.create', 'training.update',
            'training.manage', 'training.assign', 'training.complete',
            'training.certificates.view', 'training.expiry.view',
            'assets.view', 'assets.create', 'assets.update',
            'assets.manage', 'assets.assign', 'assets.return',
            'assets.history.view',
            // Expenses: this role both files its own claims (`create` /
            // `update` mirror `leave.create`) and is the back office for
            // everybody else's — `.manage` is the permission EXP-STD's
            // "Finance / HR" link resolves to, and `.approve` is what gets
            // it past the route's coarse gate to reach that link.
            'expenses.view', 'expenses.create', 'expenses.update',
            'expenses.approve', 'expenses.manage', 'expenses.receipts.view',
            'settings.view', 'settings.manage',
            'roles.view',
            'users.view',
            'audit.view',
        ],

        'HR Executive' => [
            'dashboard.view',
            'employees.view', 'employees.create', 'employees.update',
            'departments.view',
            'designations.view',
            'attendance.view', 'attendance.manage',
            'approvals.view',
            // Reads and runs the calendar; does not define leave policy —
            // `leave.manage` and `leave.balance.manage` stay with HR Admin.
            'leave.view', 'leave.create', 'leave.approve',
            'leave.balance.view',
            'holidays.manage',
            'timesheets.view', 'timesheets.manage',
            'overtime.view', 'overtime.approve',
            'projects.view',
            'sites.view',
            'shifts.view',
            'assignments.view', 'assignments.manage',
            'reports.view',
            'site_activity_reports.view',
            'daily_site_reports.view', 'daily_site_reports.pdf',
            'documents.view', 'documents.create', 'documents.update',
            'documents.verify', 'documents.delete',
            'documents.expiry.view', 'documents.manage',
            'onboarding.view', 'onboarding.manage',
            // Exactly HR Admin's training and asset grants. Running a
            // course roster and keeping the tool store straight are this
            // role's day-to-day, and withholding `training.manage` from it
            // while handing it `documents.verify` would be an arbitrary
            // split rather than a deliberate one.
            'training.view', 'training.create', 'training.update',
            'training.manage', 'training.assign', 'training.complete',
            'training.certificates.view', 'training.expiry.view',
            'assets.view', 'assets.create', 'assets.update',
            'assets.manage', 'assets.assign', 'assets.return',
            'assets.history.view',
            'settings.view',
            // Files her own claims and is told who each one is waiting on,
            // but is not the financial sign-off: `.manage` is withheld, so
            // the last link of EXP-STD (`permission: expenses.manage`) is
            // never hers to answer.
            'expenses.view', 'expenses.create', 'expenses.update',
            'expenses.approve', 'expenses.receipts.view',
            // Own loans and own certificate requests only. Deliberately no
            // `payroll.view`, `salary_slips.*` or `loans.approve`: this role
            // runs the roster without seeing what anyone is paid, exactly as
            // `employees.salary.view` has always been withheld from it. The
            // certificate grant is `.view` and nothing else - enough to ask
            // for a document about your own salary and read the decision on
            // it, never enough to sign one off or to fetch somebody else's.
            'loans.view', 'loans.create',
            'salary_certificates.view',
        ],

        'Payroll Admin' => [
            'dashboard.view',
            'employees.view',
            'employees.salary.view',
            'attendance.view',
            // Read-only on leave, timesheets and overtime: this role is the
            // *consumer* of approved overtime and LOP days, never their
            // approver — except on the final overtime step, where "HR /
            // Payroll" is the sign-off that makes the minutes payable.
            'leave.view', 'leave.balance.view',
            'timesheets.view',
            'overtime.view', 'overtime.approve',
            'payroll.view', 'payroll.manage',
            // The only role besides Super Admin that may lock a period, and
            // the only one that may run one: processing pay is this role's
            // job rather than HR's.
            'payroll.process', 'payroll.lock', 'payroll.summary.view',
            'salary_slips.view', 'salary_slips.manage',
            'salary_certificates.view', 'salary_certificates.manage',
            'loans.view', 'loans.create', 'loans.approve', 'loans.manage',
            // A consumer of claims, not a filer of them — the same read-only
            // stance this role takes on leave and timesheets. `.update` is
            // not "may file a claim" (that is `.create`, withheld) but the
            // counterpart of `.manage`: the policy lets a manager correct a
            // draft, and the route has to let them reach it.
            // `.approve` is for the last link of EXP-STD, which resolves
            // `permission: expenses.manage` and is held by this role,
            // HR Admin and Finance.
            'expenses.view', 'expenses.update', 'expenses.approve',
            'expenses.manage', 'expenses.receipts.view',
            'reports.view', 'reports.export',
            'documents.view', 'documents.expiry.view',
            'onboarding.view',
            // Their own course history, and the cross-employee certificate
            // expiry report — the same pairing `documents.expiry.view`
            // gets, for the same reason: a lapsed safety card is a payroll
            // fact (a certified operator commands a different rate), but
            // the report is the useful half and the individual rows stay
            // narrowed to this role's own unless `training.manage` follows.
            'training.view', 'training.expiry.view',
            // Their own kit, and nothing about anyone else's: `assets.view`
            // is the coarse gate, Visibility narrows it to assets assigned
            // to this role's own employee record, and `assets.manage` is
            // what would open the rest — deliberately absent, along with
            // `purchase_cost`, which is a finance fact rather than a payroll one.
            'assets.view',
            'settings.view',
            'audit.view',
        ],

        'Project Manager' => [
            'dashboard.view',
            'employees.view',
            'projects.view', 'projects.manage',
            'sites.view', 'sites.manage',
            'attendance.view',
            'approvals.view',
            'leave.view', 'leave.create', 'leave.approve',
            'leave.balance.view',
            'timesheets.view',
            'overtime.view', 'overtime.create', 'overtime.approve',
            'assignments.view', 'assignments.manage',
            'reports.view',
            'site_activity_reports.view', 'site_activity_reports.create', 'site_activity_reports.update',
            'daily_site_reports.view', 'daily_site_reports.create', 'daily_site_reports.update',
            'daily_site_reports.manage', 'daily_site_reports.pdf',
            // Own file only. `documents.create` is the self-service door —
            // filing your own passport — and `documents.manage` is what
            // this role is pointedly NOT given: managing a person's project
            // is not a licence to open their Emirates ID.
            'documents.view', 'documents.create', 'documents.update',
            'onboarding.view',
            // Compliance reading for their own workforce, and nothing
            // more. The brief asks for PM / Site Supervisor to see
            // training compliance "only if explicitly permitted" and never
            // to reach unrelated private documents — so the grant is the
            // coarse gate alone. Visibility then keeps the rows to this
            // role's own, `training.manage` (the door onto somebody
            // else's) is absent, and `training.certificates.view` is
            // absent again: a manager who may note that a report is
            // uncertified is not handed the certificate itself, nor an
            // Emirates ID by a side road.
            'training.view',
            // Their own kit. `assets.manage` — the door onto the pool, and
            // onto `purchase_cost` — is absent for the same reason.
            'assets.view',
            // Files their own claims like anyone else, and acts on the
            // supervisor link of a chain for the team they manage — never
            // the financial sign-off, which `.manage` withholds from this
            // role along with everything else payroll-shaped.
            'expenses.view', 'expenses.create', 'expenses.update',
            'expenses.approve', 'expenses.receipts.view',
            // A loan is about *their own* pay, not anyone else's, so the
            // self-service door opens here too. Nothing in `payroll.*` is
            // granted - see the spec's "no payroll visibility by default"
            // for project and site roles.
            'loans.view', 'loans.create',
        ],

        'Site Engineer' => [
            'dashboard.view',
            'projects.view',
            'sites.view',
            'attendance.view',
            'leave.view', 'leave.create',
            'leave.balance.view',
            'timesheets.view',
            'overtime.view', 'overtime.create',
            'reports.view',
            'site_activity_reports.view', 'site_activity_reports.create', 'site_activity_reports.update',
            'daily_site_reports.view', 'daily_site_reports.create', 'daily_site_reports.update',
            'daily_site_reports.pdf',
            'documents.view', 'documents.create', 'documents.update',
            'onboarding.view',
            // Own rows only, exactly as for Project Manager: read the
            // training list, never a colleague's certificate.
            'training.view',
            'assets.view',
            // Claims are self-service exactly like leave is: read the door,
            // file your own, edit your own draft — never approve, because
            // this role is not an approver step on any chain that ships.
            'expenses.view', 'expenses.create', 'expenses.update',
            'loans.view', 'loans.create',
        ],

        'Site Supervisor' => [
            'dashboard.view',
            'employees.view',
            'projects.view',
            'sites.view',
            'attendance.view', 'attendance.manage',
            'approvals.view',
            // A supervisor is the `reporting_manager` step on most chains, so
            // they need `leave.approve` / `overtime.approve` to get past the
            // route's coarse gate; the policy then asks whether they are the
            // *current* approver. Any role used as an approver step needs one
            // of these — see docs/SECURITY.md.
            'leave.view', 'leave.create', 'leave.approve',
            'leave.balance.view',
            'timesheets.view', 'timesheets.manage',
            'overtime.view', 'overtime.create', 'overtime.approve',
            'assignments.view', 'assignments.manage',
            'reports.view',
            'site_activity_reports.view', 'site_activity_reports.create', 'site_activity_reports.update',
            'daily_site_reports.view', 'daily_site_reports.create', 'daily_site_reports.update',
            'daily_site_reports.pdf',
            'documents.view', 'documents.create', 'documents.update',
            'onboarding.view',
            // Own rows only. A supervisor is exactly the person the brief
            // warns should see compliance without being handed unrelated
            // private files — so the coarse gate and nothing behind it.
            'training.view',
            'assets.view',
            // As above, plus `.approve`: a supervisor is the
            // `reporting_manager` link of EXP-STD, so they need the coarse
            // gate on POST /expenses/{id}/approve to even reach the policy
            // that asks whether they are *this* claim's current approver.
            // `.receipts.view` follows, because approving a claim without
            // being able to open the evidence behind it is not approval.
            'expenses.view', 'expenses.create', 'expenses.update',
            'expenses.approve', 'expenses.receipts.view',
            'loans.view', 'loans.create',
        ],

        'Finance' => [
            'dashboard.view',
            'employees.view',
            'employees.salary.view',
            'payroll.view',
            // The payroll preparation view: how many days were lost to pay,
            // and what overtime is waiting to be paid. No approval rights,
            // and no right to run or lock a period.
            'payroll.summary.view',
            // Slips are read here because paying them is the reason Finance
            // exists; `.manage` is what lets an analyst pull any employee's
            // document rather than only their own.
            'salary_slips.view', 'salary_slips.manage',
            'loans.view',
            // Read-only on leave, timesheets and overtime: this role is the
            // *consumer* of approved overtime and LOP days, never their
            // approver.
            'leave.view', 'leave.balance.view',
            'timesheets.view',
            'overtime.view',
            // The consumer, and the sign-off: `.manage` is the permission
            // EXP-STD's "Finance / HR" link resolves to, `.approve` is the
            // route gate in front of it, `.update` is the counterpart that
            // lets the same policy right reach the endpoint, and
            // `.receipts.view` is what makes the evidence behind a claim
            // openable to the people paying it. Deliberately no
            // `.create`: this role does not file claims of its own, the
            // way it does not file leave either.
            'expenses.view', 'expenses.update', 'expenses.approve',
            'expenses.manage', 'expenses.receipts.view',
            'reports.view', 'reports.export',
            'documents.view',
            'onboarding.view',
            // Read-only, like every other module this role touches:
            // their own course history and their own kit, narrowed by
            // Visibility because `training.manage` and `assets.manage`
            // are both absent. `purchase_cost` is likewise withheld —
            // Finance may see an asset's cost through the master record it
            // actually maintains, not by browsing a colleague's desk.
            'training.view',
            'assets.view',
            'audit.view',
        ],

        'Management' => [
            'dashboard.view',
            'employees.view',
            'projects.view',
            'sites.view',
            'attendance.view',
            'leave.view', 'leave.create', 'leave.approve',
            'leave.balance.view',
            'timesheets.view',
            'overtime.view', 'overtime.approve',
            'payroll.view',
            // Summary only. Management reads the company's payroll totals
            // and its own row - `payroll.view` without `payroll.manage` or
            // `employees.salary.view` narrows Visibility to the caller's own
            // record, so this role is not accidentally handed every salary
            // in the building. `payroll.summary.view` is the door to the
            // aggregate, which deliberately returns no employee-level rows.
            'payroll.summary.view',
            'loans.view', 'loans.create',
            'reports.view', 'reports.export',
            'site_activity_reports.view',
            'daily_site_reports.view', 'daily_site_reports.pdf',
            'documents.view',
            'onboarding.view',
            // Read-only on training, plus the one extra the brief calls
            // out: "summary/read access if granted". `assets.history.view`
            // lets this role read who has held what — a hand-over log is a
            // control document, not a personal file — while `assets.manage`
            // stays absent, so the master record and `purchase_cost`
            // remain HR's. `training.certificates.view` is absent for the
            // same reason it is absent from every non-HR role.
            'training.view',
            'assets.view', 'assets.history.view',
            // Deliberately `.view` and nothing else. Management reads
            // expense records but does not file them, does not approve them
            // and does not open the receipts attached to them — read-only is
            // the whole of this role's access to the module, and Visibility
            // narrows even that read to its own rows.
            'expenses.view',
            'audit.view',
        ],

        'Employee' => [
            'dashboard.view',
            // Their own attendance, and nothing else: `attendance.view` is
            // the coarse gate that lets GET /attendance through, and
            // Visibility::attendanceIsVisible() then narrows the rows to
            // their own because this role is not listed as an overseer and
            // holds neither employees.view nor attendance.manage. Without
            // this grant an employee could not read back the day they
            // themselves recorded.
            'attendance.view',
            // The same shape for leave, timesheets and overtime: enough to
            // ask, read your own and see your own pot — never `leave.approve`,
            // because nobody signs off on their own absence.
            'leave.view', 'leave.create',
            'leave.balance.view',
            'timesheets.view',
            'overtime.view', 'overtime.create',
            // The field worker's own reporting door. Creating a *site
            // activity report is writing your own note about your own site,
            // so the coarse gate goes to every account expected to stand on
            // one; Visibility::attachedSiteIds() then narrows "which site?"
            // to the ones this person is actually assigned to, which is why
            // an Employee with no assignment is refused rather than offered
            // a picker of every site in the company. Deliberately NOT
            // `daily_site_reports.*` — the official site-day record is a
            // supervisor's artefact, and two official reports for one
            // site-date is a contradiction.
            'site_activity_reports.view',
            'site_activity_reports.create',
            'site_activity_reports.update',
            // Their own file, and the door to add to it. `documents.create`
            // and `.update` are filing and correcting *their own* documents;
            // `documents.manage` is absent, which is what keeps this role
            // reading its own passport and nobody else's.
            'documents.view', 'documents.create', 'documents.update',
            // Their own onboarding checklist, and nothing else: the list
            // and the detail both narrow to the caller's own employee row
            // unless `onboarding.manage` is held.
            'onboarding.view',
            // Their own course history and their own kit, and nothing
            // else. Both doors are coarse gates that Visibility then
            // narrows to this person's own rows: `training.manage` and
            // `assets.manage` are absent, which is what stops an employee
            // reading a colleague's certificate or browsing the pool —
            // and `training.assign`, `training.complete` and
            // `assets.assign` are absent again, because HR puts people on
            // courses and hands out the tools, not the other way round.
            'training.view',
            'assets.view',
            // Their own claims, and nothing else. `expenses.view` is the
            // coarse gate that lets GET /expenses through at all —
            // Visibility then narrows every row to their own, because this
            // role holds neither `expenses.manage` nor `expenses.approve`.
            // `.create` / `.update` are filing and correcting a draft of
            // their own; `.approve` is deliberately absent, because nobody
            // signs off on their own spend. Their own receipts read back
            // through ownership, so `expenses.receipts.view` is not needed.
            'expenses.view', 'expenses.create', 'expenses.update',
            // Their own pay, their own loans, their own certificates - the
            // three doors an employee needs to answer "what am I paid?" for
            // themselves. `payroll.view` is granted for exactly the same
            // reason `leave.view` is: without the coarse gate the endpoint
            // is a 403 before any row-level rule can run, and Visibility
            // then narrows it to their own rows because this role holds
            // neither `payroll.manage` nor `employees.salary.view`.
            'payroll.view',
            'salary_slips.view',
            'salary_certificates.view',
            'loans.view', 'loans.create',
        ],
    ];

    public function run(): void
    {
        // Work from the authoritative list in PermissionSeeder, not a cached
        // copy, so a stale registrar cache can never hide a permission.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = PermissionSeeder::flat();

        foreach (self::MAP as $roleName => $grants) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $names = $grants === ['*'] ? $all : array_values(array_intersect($grants, $all));

            // Sync (not assign) so removing a permission from this map also
            // removes it from the database on the next seed.
            $role->syncPermissions($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
