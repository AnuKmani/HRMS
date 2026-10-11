<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * The ten roles defined for the HRMS. Slugs are stored as given so the
     * API/Flutter never has to guess at capitalisation.
     *
     * @var array<int, string>
     */
    public const ROLES = [
        'Super Admin',
        'HR Admin',
        'HR Executive',
        'Payroll Admin',
        'Operations Manager',
        'Senior Project Manager',
        'Project Manager',
        'Project Coordinator',
        'Site Engineer',
        'Site Supervisor',
        'Site Officer',
        'Technician',
        'Sales',
        'Finance',
        'Management',
        'Employee',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }
}
