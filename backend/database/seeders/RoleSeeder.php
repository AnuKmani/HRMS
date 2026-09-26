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
        'Project Manager',
        'Site Engineer',
        'Site Supervisor',
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
