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
        'leave' => [
            'leave.view',
            'leave.request',
            'leave.approve',
            'leave.manage',
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
    }
}
