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
            'leave.view', 'leave.request', 'leave.approve', 'leave.manage',
            'payroll.view',
            'projects.view',
            'sites.view',
            'shifts.view', 'shifts.manage',
            'assignments.view', 'assignments.manage',
            'reports.view', 'reports.export',
            'documents.view', 'documents.manage',
            'expenses.view',
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
            'leave.view', 'leave.request', 'leave.approve',
            'projects.view',
            'sites.view',
            'shifts.view',
            'assignments.view', 'assignments.manage',
            'reports.view',
            'documents.view', 'documents.manage',
            'settings.view',
        ],

        'Payroll Admin' => [
            'dashboard.view',
            'employees.view',
            'employees.salary.view',
            'attendance.view',
            'leave.view',
            'payroll.view', 'payroll.manage',
            'expenses.view', 'expenses.manage',
            'reports.view', 'reports.export',
            'documents.view',
            'settings.view',
            'audit.view',
        ],

        'Project Manager' => [
            'dashboard.view',
            'employees.view',
            'projects.view', 'projects.manage',
            'sites.view', 'sites.manage',
            'attendance.view',
            'leave.view', 'leave.approve',
            'assignments.view', 'assignments.manage',
            'reports.view',
            'documents.view',
            'expenses.view', 'expenses.approve',
        ],

        'Site Engineer' => [
            'dashboard.view',
            'projects.view',
            'sites.view',
            'attendance.view',
            'reports.view',
            'documents.view',
        ],

        'Site Supervisor' => [
            'dashboard.view',
            'employees.view',
            'projects.view',
            'sites.view',
            'attendance.view', 'attendance.manage',
            'leave.view', 'leave.request',
            'assignments.view', 'assignments.manage',
            'reports.view',
            'documents.view',
        ],

        'Finance' => [
            'dashboard.view',
            'employees.view',
            'employees.salary.view',
            'payroll.view',
            'expenses.view', 'expenses.approve', 'expenses.manage',
            'reports.view', 'reports.export',
            'documents.view',
            'audit.view',
        ],

        'Management' => [
            'dashboard.view',
            'employees.view',
            'projects.view',
            'sites.view',
            'attendance.view',
            'leave.view',
            'payroll.view',
            'reports.view', 'reports.export',
            'documents.view',
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
            'leave.view', 'leave.request',
            'documents.view',
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
