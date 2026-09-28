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
        'payroll' => [
            'payroll.view',
            'payroll.manage',
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
        'documents' => [
            'documents.view',
            'documents.manage',
        ],
        'expenses' => [
            'expenses.view',
            'expenses.approve',
            'expenses.manage',
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
