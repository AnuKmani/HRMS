<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Every permission in the system, grouped by module.
     *
     * Naming convention: `{resource}.{action}` — always lowercase, always
     * dot-separated. Adding a module later = adding a line here; nothing else
     * in the codebase needs to know the full list.
     *
     * @var array<string, array<int, string>>
     */
    public const PERMISSIONS = [
        'dashboard' => [
            'dashboard.view',
        ],
        'employees' => [
            'employees.view',
            'employees.create',
            'employees.update',
            'employees.delete',

            // Three segments on purpose. Salary is not a row an employee
            // record happens to carry — it is a payroll fact that sits on
            // top of the record, so it needs its own gate rather than
            // riding along with `employees.view`. Held by HR Admin,
            // Payroll Admin and Finance; deliberately NOT by HR Executive,
            // who may maintain the roster without seeing what anyone is
            // paid. Super Admin holds it through `*`.
            'employees.salary.view',
        ],
        'departments' => [
            'departments.view',
            'departments.manage',
        ],
        'designations' => [
            'designations.view',
            'designations.manage',
        ],
        'attendance' => [
            'attendance.view',
            'attendance.manage',
        ],
        /*
        | Approval chains (Phase 6).
        |
        | Deliberately a separate module from `leave` rather than folded into
        | it: a workflow applies to leave *and* overtime, so gating its
        | configuration behind `leave.manage` would make editing an overtime
        | chain a leave operation. Held by HR Admin and Super Admin only —
        | changing who signs off on absences is a policy change, not a daily
        | task.
        */
        'approvals' => [
            'approvals.view',
            'approvals.manage',
        ],
        'leave' => [
            // `leave.create` replaces the Phase 2 placeholder `leave.request`.
            // Every other module spells the coarse write gate `{resource}.create`
            // (employees.create, …) and leave was the one outlier; the old name
            // was never wired to a route. Retired in PermissionSeeder::RETIRED
            // so re-seeding removes it rather than leaving a permission nobody
            // can reason about in the table.
            'leave.view',
            'leave.create',
            'leave.approve',
            'leave.manage',

            // Three segments, for the same reason `employees.salary.view` is
            // three: seeing a pot of days is a different question from
            // changing one. Balance *reads* go to anybody who may take leave,
            // balance *writes* (entitlement, carry-forward, adjustment) to HR.
            'leave.balance.view',
            'leave.balance.manage',
        ],
        'holidays' => [
            // Write-only, on purpose: the holiday calendar is readable by
            // every signed-in account through HolidayPolicy — a day off is
            // information about the company, not a privilege — so there is no
            // `holidays.view` to grant.
            'holidays.manage',
        ],
        'timesheets' => [
            'timesheets.view',
            'timesheets.manage',
        ],
        'overtime' => [
            'overtime.view',
            'overtime.create',
            'overtime.approve',
            'overtime.manage',
        ],
        /*
        | Payroll (Phase 8).
        |
        | Five rather than two, because payroll is where the coarse gate has
        | to carry the most weight - a single `payroll.view` shared by an
        | employee reading their own slip and an admin processing the whole
        | company would leave every other distinction to the policies alone.
        |
        |   payroll.view          the module is open to you. Held by Employee
        |                         too, so Visibility can narrow the rows to
        |                         their own - the same arrangement leave.view
        |                         has used since Phase 6.
        |   payroll.manage        allowances, adjustments, reviews: the
        |                         "may change what goes in" grant.
        |   payroll.process       run and re-run the calculation. Separated
        |                         from `manage` so a role may correct an
        |                         allowance without being able to restate a
        |                         month's pay.
        |   payroll.lock          make it final. The narrowest grant in the
        |                         system, and there is no unlock.
        |   payroll.summary.view  company totals WITHOUT employee-level rows,
        |                         so a role can be given the number and not
        |                         the names. Three segments, the same
        |                         sub-field convention `employees.salary.view`
        |                         established.
        */
        'payroll' => [
            'payroll.view',
            'payroll.manage',
            'payroll.process',
            'payroll.lock',
            'payroll.summary.view',
        ],
        /*
        | Salary slips (Phase 8).
        |
        | `.view` is held by every role that should see a payslip at all -
        | including Employee, and including only their own rows, narrowed by
        | Visibility exactly as `payroll.view` is. `.manage` is the override
        | that lets a holder read *anybody's* slip, which is what separates
        | "download mine" from "download the company's".
        */
        'salary_slips' => [
            'salary_slips.view',
            'salary_slips.manage',
        ],
        /*
        | Salary certificates (Phase 8).
        |
        | `.view` doubles as the right to *ask* for one - an employee cannot
        | hold a permission to request a document about themselves that they
        | are then refused for asking. `.manage` is the decision: approve,
        | reject, and issue the PDF.
        */
        'salary_certificates' => [
            'salary_certificates.view',
            'salary_certificates.manage',
        ],
        /*
        | Loans and salary advances (Phase 8).
        |
        | Same four-verb shape as leave. `.view` + `.create` are the
        | self-service door (own rows only - Visibility fails closed); the
        | decision pair is `.approve` for the act and `.manage` for
        | correction afterwards. Nobody may approve their own loan: the rule
        | is checked in LoanPolicy as well as in LoanService, because it is a
        | property of approval rather than of any one screen.
        */
        'loans' => [
            'loans.view',
            'loans.create',
            'loans.approve',
            'loans.manage',
        ],
        'projects' => [
            'projects.view',
            'projects.manage',
        ],
        'sites' => [
            'sites.view',
            'sites.manage',
        ],
        'shifts' => [
            'shifts.view',
            'shifts.manage',
        ],
        'assignments' => [
            'assignments.view',
            'assignments.manage',
        ],
        'reports' => [
            'reports.view',
            'reports.export',
        ],
        /*
        | Site reporting (Phase 7).
        |
        | Two modules rather than one, because they answer different
        | questions. A *site activity report* is a person's note about what
        | they did at a site today — the person who wrote it owns it, and
        | `site_activity_reports.create` is held by everyone expected to be
        | standing on the site. A *daily site report* is the official record
        | of a site-day: one per site per date, prepared by a supervisor,
        | read by back-office, and the artefact a PDF is generated from.
        | Holding `daily_site_reports.create` therefore excludes Employees on
        | purpose — two "official" reports for the same day is a contradiction
        | nobody can resolve later, so the door is not offered.
        |
        | `daily_site_reports.manage` is approval/override authority (and the
        | one role that may file a report for a site it does not run);
        | `daily_site_reports.pdf` is separated from `.view` so a reader can
        | be given the numbers without being given a document they could
        | forward.
        */
        'site_activity_reports' => [
            'site_activity_reports.view',
            'site_activity_reports.create',
            'site_activity_reports.update',
        ],
        'daily_site_reports' => [
            'daily_site_reports.view',
            'daily_site_reports.create',
            'daily_site_reports.update',
            'daily_site_reports.manage',
            'daily_site_reports.pdf',
        ],
        /*
        | Employee documents (Phase 10).
        |
        | Seven permissions rather than the two that existed before, and
        | the split is the whole security story of the module.
        |
        | `documents.view` is the COARSE gate: may this role open the
        | documents screen at all? It is held by almost everybody, because
        | almost everybody should be able to see *their own* file — and it
        | is therefore worth nothing on its own. Visibility::mayViewOthersDocuments()
        | narrows every row to the caller's own unless the same account also
        | holds `documents.manage`, which is the explicit "you are trusted
        | with other people's passports" grant and is held by HR only.
        |
        | That is what stops a Project Manager reading a report's direct's
        | Emirates ID merely because they manage that person: managing
        | somebody is not a permission about their documents, and none of
        | `employees.view`, `attendance.manage` or `expenses.approve` opens
        | this door.
        |
        | `documents.create` / `.update` are self-service — filing and
        | correcting your own file — and become "anybody's" only in hands
        | that already hold `documents.manage`, checked in
        | EmployeeDocumentPolicy::storeFor() rather than here.
        |
        | `documents.verify` is the decision to accept a document as genuine.
        | Separate from `.manage` because it is a *different act*: HR
        | Executive may both, but an operator who wants a role to file
        | paperwork without being able to sign it off can now say so.
        |
        | `documents.delete` is archive, never destruction — see
        | EmployeeDocumentController::destroy(). Held by HR alone.
        |
        | `documents.expiry.view` is the expiry *report* — the cross-employee
        | "what is about to lapse" view. Separate from `.view` because the
        | answer is a question about the whole workforce rather than about
        | your own file, and an employee has no business seeing it.
        */
        'documents' => [
            'documents.view',
            'documents.create',
            'documents.update',
            'documents.verify',
            'documents.delete',
            'documents.expiry.view',
            'documents.manage',
        ],
        /*
        | Onboarding (Phase 10).
        |
        | Two doors. `onboarding.view` is held by everybody, because every
        | employee should be able to see where their own onboarding stands
        | — and it is narrowed to their own row unless they also hold
        | `onboarding.manage`, which is HR's and is what the list, the
        | status change and the completion check all sit behind.
        */
        'onboarding' => [
            'onboarding.view',
            'onboarding.manage',
        ],
        'expenses' => [
            'expenses.view',
            'expenses.create',
            'expenses.update',
            'expenses.approve',
            'expenses.manage',

            // Three segments, for the reason `leave.balance.view` and
            // `employees.salary.view` are: seeing a list of claims is one
            // act, being handed the document behind one is another. A
            // receipt is somebody's invoice or card slip, so it gets its
            // own door — held by whoever may read claims they did not file
            // themselves. The person who filed it reads their own back
            // through ownership, without needing this.
            'expenses.receipts.view',
        ],
        'settings' => [
            'settings.view',
            'settings.manage',
        ],
        'roles' => [
            'roles.view',
            'roles.manage',
        ],
        'users' => [
            'users.view',
            'users.manage',
        ],
        'audit' => [
            'audit.view',
        ],
    ];

    /**
     * Permissions that have been renamed out of existence.
     *
     * `firstOrCreate` cannot remove anything, so without this a permission
     * that no longer appears anywhere in the codebase would sit in the table
     * forever, still granted to roles that no longer have a route behind it.
     * An explicit list beats purging everything not in the catalog — an
     * operator may legitimately have added one of their own.
     *
     * @var array<int, string>
     */
    public const RETIRED = [
        // Renamed to `leave.create` in Phase 6 to match {resource}.{action}.
        'leave.request',
    ];

    /**
     * @return array<int, string>
     */
    public static function flat(): array
    {
        return array_values(array_merge(...array_values(self::PERMISSIONS)));
    }

    public function run(): void
    {
        foreach (self::PERMISSIONS as $group => $permissions) {
            foreach ($permissions as $permission) {
                Permission::firstOrCreate([
                    'name' => $permission,
                    'guard_name' => 'web',
                ], [
                    'name' => $permission,
                    'guard_name' => 'web',
                ]);
            }
        }

        foreach (self::RETIRED as $permission) {
            // `model_has_permissions` cascades on delete, so a role that held
            // it is simply one grant lighter — and RolePermissionSeeder runs
            // straight afterwards and re-syncs from the catalog anyway.
            Permission::query()
                ->where('name', $permission)
                ->delete();
        }
    }
}
