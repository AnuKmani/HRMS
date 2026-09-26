<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * ⚠️  DEVELOPMENT DATA — NOT PRODUCTION.
 *
 * Sample departments, designations and the four standard shifts so the app is
 * usable locally. A production install must wipe/re-run this seeder with the
 * organisation's real structure. No employees, users or salaries are created
 * here, ever.
 */
class DevelopmentDataSeeder extends Seeder
{
    /** @var array<int, array{name: string, code: string, designations: array<int, array<string, string>>}> */
    public const DEPARTMENTS = [
        [
            'name' => 'Human Resources',
            'code' => 'HR',
            'designations' => [
                ['name' => 'HR Manager', 'code' => 'HR-MGR'],
                ['name' => 'HR Executive', 'code' => 'HR-EXE'],
            ],
        ],
        [
            'name' => 'Information Technology',
            'code' => 'IT',
            'designations' => [
                ['name' => 'Project Manager', 'code' => 'IT-PM'],
                ['name' => 'Software Engineer', 'code' => 'IT-SE'],
            ],
        ],
        [
            'name' => 'Operations',
            'code' => 'OPS',
            'designations' => [
                ['name' => 'Site Supervisor', 'code' => 'OPS-SS'],
                ['name' => 'Site Engineer', 'code' => 'OPS-SITE'],
            ],
        ],
        [
            'name' => 'Finance & Accounts',
            'code' => 'FIN',
            'designations' => [
                ['name' => 'Accountant', 'code' => 'FIN-ACC'],
                ['name' => 'Finance Manager', 'code' => 'FIN-MGR'],
            ],
        ],
    ];

    /**
     * name, code, start, end, break (min), grace (min), min hours, OT hours.
     *
     * @var array<int, array<string, string|int>>
     */
    public const SHIFTS = [
        ['name' => 'General', 'code' => 'GEN', 'start' => '09:00:00', 'end' => '18:00:00', 'break' => 60, 'grace' => 10, 'min_hours' => 8, 'ot' => 2],
        ['name' => 'Morning', 'code' => 'MOR', 'start' => '06:00:00', 'end' => '15:00:00', 'break' => 45, 'grace' => 10, 'min_hours' => 8, 'ot' => 2],
        ['name' => 'Evening', 'code' => 'EVE', 'start' => '14:00:00', 'end' => '23:00:00', 'break' => 45, 'grace' => 10, 'min_hours' => 8, 'ot' => 2],
        // Crosses midnight — Shift::crossesMidnight() derives the flag.
        ['name' => 'Night', 'code' => 'NGT', 'start' => '22:00:00', 'end' => '06:00:00', 'break' => 45, 'grace' => 15, 'min_hours' => 8, 'ot' => 3],
    ];

    public function run(): void
    {
        foreach (self::SHIFTS as $shift) {
            Shift::query()->updateOrCreate(
                ['code' => $shift['code']],
                [
                    'name' => $shift['name'],
                    'start_time' => $shift['start'],
                    'end_time' => $shift['end'],
                    'break_duration' => $shift['break'],
                    'grace_period' => $shift['grace'],
                    'minimum_working_hours' => $shift['min_hours'],
                    'overtime_threshold' => $shift['ot'],
                    'status' => Shift::STATUS_ACTIVE,
                ],
            );
        }

        foreach (self::DEPARTMENTS as $department) {
            $model = Department::query()->updateOrCreate(
                ['code' => $department['code']],
                [
                    'name' => $department['name'],
                    'description' => 'Development sample data.',
                    'status' => Department::STATUS_ACTIVE,
                ],
            );

            foreach ($department['designations'] as $designation) {
                Designation::query()->updateOrCreate(
                    ['code' => $designation['code']],
                    [
                        'department_id' => $model->id,
                        'name' => $designation['name'],
                        'description' => 'Development sample data.',
                        'status' => Designation::STATUS_ACTIVE,
                    ],
                );
            }
        }
    }
}
