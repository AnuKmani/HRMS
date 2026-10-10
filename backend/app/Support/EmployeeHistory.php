<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Has this person left a trace in the system?
 *
 * Profile-only data (bank details, allowances, balances the system set up
 * by itself) does not count — those belong to the record, not to its
 * history. Everything listed here is something that happened.
 */
final class EmployeeHistory
{
    /** table => what to call it. */
    private const TABLES = [
        'attendances' => 'attendance',
        'leave_requests' => 'leave',
        'payrolls' => 'payroll',
        'overtime_requests' => 'overtime',
        'timesheets' => 'timesheets',
        'expenses' => 'expenses',
        'loans' => 'loans',
        'site_visits' => 'site visits',
        'site_visit_tasks' => 'planned site visits',
        'site_activity_reports' => 'site reports',
        'employee_site_assignments' => 'site postings',
        'salary_certificate_requests' => 'salary certificates',
        'asset_assignments' => 'assets',
        'employee_documents' => 'documents',
        'employee_trainings' => 'training',
    ];

    /**
     * @return array<int, string> the kinds of record found, empty if none
     */
    public static function summary(Employee $employee): array
    {
        $found = [];

        foreach (self::TABLES as $table => $label) {
            if (Schema::hasTable($table)
                && DB::table($table)->where('employee_id', $employee->id)->exists()) {
                $found[] = $label;
            }
        }

        if ($employee->user_id !== null
            && Schema::hasTable('daily_site_reports')
            && DB::table('daily_site_reports')->where('created_by', $employee->user_id)->exists()) {
            $found[] = 'daily site reports';
        }

        return $found;
    }
}
