<?php

namespace App\Services\Reporting;

use App\Models\Asset;
use App\Models\Attendance;
use App\Models\DailySiteReport;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeTraining;
use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\Site;
use App\Models\User;

/**
 * The thirteen reports, and nothing else.
 *
 * Every one of them is a `SELECT` over rows that already exist behind an
 * endpoint somebody's permission already guards — there is no report here
 * that reads a column no screen shows, and no calculation that is not the
 * same arithmetic a page in the app does. That is the whole reason this
 * file can be reviewed: a report is a permission, a date column, a status
 * column and some columns to print, and adding a fourteenth means copying
 * a block, not writing a service.
 *
 * Two conventions worth stating once, because every block follows them:
 *
 *  - **Names are concatenated in SQL** with `TRIM(CONCAT(...))` rather
 *    than loaded through `Employee::$full_name`. A report of 40,000 rows
 *    must not hydrate 40,000 models to read one accessor, and the
 *    expression is written to produce byte-identical output to the
 *    accessor (including its double space when there is no middle name),
 *    so a figure that agrees on a screen agrees in a CSV.
 *  - **Nothing sensitive is a column.** `employees.salary` is *not* a
 *    column of the directory — payroll figures appear only in the
 *    payroll register, which is gated by `payroll.view` rather than by
 *    `employees.view`. Two reports, two permissions, no accidental
 *    promotion of a salary into a document a wider role may export.
 */
final class ReportRegistry
{
    /**
     * Byte-identical to `Employee::$full_name`: trim the outer spaces,
     * keep the gap a missing middle name leaves.
     */
    private const NAME = "TRIM(CONCAT(employees.first_name, ' ', COALESCE(employees.middle_name, ''), ' ', employees.last_name))";

    /**
     * @var array<string, ReportDefinition>|null
     */
    private static ?array $reports = null;

    private function __construct() {}

    /**
     * @return array<string, ReportDefinition>
     */
    public static function all(): array
    {
        return self::$reports ??= self::build();
    }

    public static function get(string $key): ?ReportDefinition
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The catalogue a given user may actually run — the route gate
     * (`reports.view`) has already passed by the time this is called, so
     * what is left is the per-report permission.
     *
     * @return array<int, ReportDefinition>
     */
    public static function visible(User $user): array
    {
        return array_values(array_filter(
            self::all(),
            fn (ReportDefinition $report): bool => $user->can($report->permission),
        ));
    }

    /**
     * @return array<string, ReportDefinition>
     */
    private static function build(): array
    {
        $reports = [
            self::attendanceDaily(),
            self::attendanceSummary(),
            self::leaveRegister(),
            self::payrollRegister(),
            self::overtimeSummary(),
            self::sitesStatus(),
            self::siteReportsDaily(),
            self::employeesDirectory(),
            self::documentsExpiry(),
            self::trainingExpiry(),
            self::expensesClaims(),
            self::loansOutstanding(),
            self::assetsRegister(),
        ];

        return array_combine(array_column($reports, 'key'), $reports);
    }

    /* --------------------------------------------------------- the reports */

    private static function attendanceDaily(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'attendance.daily',
            title: 'Daily attendance',
            description: 'One row per recorded day: when somebody clocked in and out, how long they worked, how late they were.',
            permission: 'attendance.view',
            columns: [
                ['key' => 'attendance_date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'site', 'label' => 'Site', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                ['key' => 'check_in_at', 'label' => 'Check in', 'type' => 'datetime'],
                ['key' => 'check_out_at', 'label' => 'Check out', 'type' => 'datetime'],
                ['key' => 'working_minutes', 'label' => 'Worked (min)', 'type' => 'minutes'],
                ['key' => 'late_minutes', 'label' => 'Late (min)', 'type' => 'minutes'],
                ['key' => 'overtime_minutes', 'label' => 'Overtime (min)', 'type' => 'minutes'],
            ],
            filterColumns: [
                'from' => 'attendances.attendance_date',
                'to' => 'attendances.attendance_date',
                'site_id' => 'attendances.site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'attendances.employee_id',
                'status' => 'attendances.status',
            ],
            builder: fn () => Attendance::query()
                ->join('employees', 'employees.id', '=', 'attendances.employee_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->leftJoin('sites', 'sites.id', '=', 'attendances.site_id')
                ->selectRaw(sprintf(
                    'attendances.attendance_date, employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, COALESCE(sites.name, %s) as site, attendances.status, attendances.check_in_at, attendances.check_out_at, attendances.working_minutes, attendances.late_minutes, attendances.overtime_minutes',
                    self::NAME,
                    "''",
                    "''",
                )),
            orderBy: 'attendances.attendance_date, attendances.id',
        );
    }

    private static function attendanceSummary(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'attendance.summary',
            title: 'Attendance summary',
            description: 'Totals per employee over the period: days recorded, days present, days late, and the minutes behind them.',
            permission: 'attendance.view',
            columns: [
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'days_recorded', 'label' => 'Days recorded', 'type' => 'number'],
                ['key' => 'days_present', 'label' => 'Days present', 'type' => 'number'],
                ['key' => 'days_late', 'label' => 'Days late', 'type' => 'number'],
                ['key' => 'working_minutes', 'label' => 'Worked (min)', 'type' => 'minutes'],
                ['key' => 'late_minutes', 'label' => 'Late (min)', 'type' => 'minutes'],
                ['key' => 'overtime_minutes', 'label' => 'Overtime (min)', 'type' => 'minutes'],
            ],
            filterColumns: [
                'from' => 'attendances.attendance_date',
                'to' => 'attendances.attendance_date',
                'site_id' => 'attendances.site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'attendances.employee_id',
                'status' => 'attendances.status',
            ],
            builder: fn () => Attendance::query()
                ->join('employees', 'employees.id', '=', 'attendances.employee_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                // Every non-aggregated column is named explicitly rather
                // than left to a functional-dependency guess: the totals
                // are one row per employee only if the group key says so,
                // and a database that has never seen this query before
                // (only_full_group_by on by default in MySQL, off in
                // MariaDB) must be able to prove each column follows.
                ->groupByRaw('employees.id, employees.employee_code, employees.first_name, employees.middle_name, employees.last_name, departments.name')
                ->selectRaw(sprintf(
                    'employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department,
                    COUNT(*) as days_recorded,
                    SUM(CASE WHEN attendances.status IN (%s) THEN 1 ELSE 0 END) as days_present,
                    SUM(CASE WHEN attendances.status = (%s) THEN 1 ELSE 0 END) as days_late,
                    COALESCE(SUM(attendances.working_minutes), 0) as working_minutes,
                    COALESCE(SUM(attendances.late_minutes), 0) as late_minutes,
                    COALESCE(SUM(attendances.overtime_minutes), 0) as overtime_minutes',
                    self::NAME,
                    "''",
                    "'present','late'",
                    "'late'",
                )),
            orderBy: 'employees.employee_code, employees.id',
        );
    }

    private static function leaveRegister(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'leave.register',
            title: 'Leave register',
            description: 'Every leave request in the period with the type, the days asked for and where it ended up.',
            permission: 'leave.view',
            columns: [
                ['key' => 'start_date', 'label' => 'From', 'type' => 'date'],
                ['key' => 'end_date', 'label' => 'To', 'type' => 'date'],
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'leave_type', 'label' => 'Leave type', 'type' => 'text'],
                ['key' => 'requested_days', 'label' => 'Days', 'type' => 'number'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'leave_requests.start_date',
                'to' => 'leave_requests.start_date',
                'site_id' => 'leave_requests.site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'leave_requests.employee_id',
                'status' => 'leave_requests.status',
            ],
            builder: fn () => LeaveRequest::query()
                ->join('employees', 'employees.id', '=', 'leave_requests.employee_id')
                ->leftJoin('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw(sprintf(
                    'leave_requests.start_date, leave_requests.end_date, employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, COALESCE(leave_types.name, %s) as leave_type, leave_requests.requested_days, leave_requests.status',
                    self::NAME,
                    "''",
                    "''",
                )),
            orderBy: 'leave_requests.start_date, leave_requests.id',
        );
    }

    private static function payrollRegister(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'payroll.register',
            title: 'Payroll register',
            description: 'Basic, allowances, deductions and net for every salary in the period, by status.',
            permission: 'payroll.view',
            columns: [
                ['key' => 'period_start', 'label' => 'Period start', 'type' => 'date'],
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'basic_salary', 'label' => 'Basic', 'type' => 'money'],
                ['key' => 'total_allowances', 'label' => 'Allowances', 'type' => 'money'],
                ['key' => 'gross_salary', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'total_deductions', 'label' => 'Deductions', 'type' => 'money'],
                ['key' => 'net_salary', 'label' => 'Net', 'type' => 'money'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'payrolls.period_start',
                'to' => 'payrolls.period_start',
                // A payroll row has no site of its own, so "which site" is
                // asked of the person being paid — their home base. That
                // is what a payroll clerk filtering by yard means, and it
                // is honest about being an employee attribute rather than
                // pretending the slip was raised against a site.
                'site_id' => 'employees.primary_site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'payrolls.employee_id',
                'status' => 'payrolls.status',
            ],
            builder: fn () => Payroll::query()
                ->join('employees', 'employees.id', '=', 'payrolls.employee_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw(sprintf(
                    'payrolls.period_start, employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, payrolls.basic_salary, payrolls.total_allowances, payrolls.gross_salary, payrolls.total_deductions, payrolls.net_salary, payrolls.status',
                    self::NAME,
                    "''",
                )),
            orderBy: 'payrolls.period_start, payrolls.id',
        );
    }

    private static function overtimeSummary(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'overtime.summary',
            title: 'Overtime requests',
            description: 'Requested and approved overtime minutes, per request, with the decision it reached.',
            permission: 'overtime.view',
            columns: [
                ['key' => 'overtime_date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'site', 'label' => 'Site', 'type' => 'text'],
                ['key' => 'requested_minutes', 'label' => 'Requested (min)', 'type' => 'minutes'],
                ['key' => 'approved_minutes', 'label' => 'Approved (min)', 'type' => 'minutes'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'overtime_requests.overtime_date',
                'to' => 'overtime_requests.overtime_date',
                'site_id' => 'overtime_requests.site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'overtime_requests.employee_id',
                'status' => 'overtime_requests.status',
            ],
            builder: fn () => OvertimeRequest::query()
                ->join('employees', 'employees.id', '=', 'overtime_requests.employee_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->leftJoin('sites', 'sites.id', '=', 'overtime_requests.site_id')
                ->selectRaw(sprintf(
                    'overtime_requests.overtime_date, employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, COALESCE(sites.name, %s) as site, overtime_requests.requested_minutes, overtime_requests.approved_minutes, overtime_requests.status',
                    self::NAME,
                    "''",
                    "''",
                )),
            orderBy: 'overtime_requests.overtime_date, overtime_requests.id',
        );
    }

    private static function sitesStatus(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'sites.status',
            title: 'Site status',
            description: 'Every site with its project, who runs it, and whether it is live.',
            permission: 'sites.view',
            columns: [
                ['key' => 'code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'name', 'label' => 'Site', 'type' => 'text'],
                ['key' => 'project', 'label' => 'Project', 'type' => 'text'],
                ['key' => 'site_manager', 'label' => 'Site manager', 'type' => 'text'],
                ['key' => 'address', 'label' => 'Address', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                ['key' => 'created_at', 'label' => 'Created', 'type' => 'date'],
            ],
            filterColumns: [
                'from' => 'sites.created_at',
                'to' => 'sites.created_at',
                'site_id' => 'sites.id',
                // There is no employee on a site until you pick a role, so
                // "sites by employee" means the sites a person manages —
                // the only relationship a site has to an employee that is
                // *owned* by the site rather than by an assignment row.
                'department_id' => 'employees.department_id',
                'employee_id' => 'sites.site_manager_id',
                'status' => 'sites.status',
            ],
            builder: fn () => Site::query()
                ->leftJoin('projects', 'projects.id', '=', 'sites.project_id')
                ->leftJoin('employees', 'employees.id', '=', 'sites.site_manager_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw(sprintf(
                    'sites.code, sites.name, COALESCE(projects.name, %s) as project, COALESCE(%s, %s) as site_manager, COALESCE(sites.address, %s) as address, sites.status, sites.created_at',
                    "''",
                    self::NAME,
                    "''",
                    "''",
                )),
            orderBy: 'sites.code, sites.id',
        );
    }

    private static function siteReportsDaily(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'site-reports.daily',
            title: 'Daily site reports',
            description: 'One row per site report: who filed it, the manpower counted, and where it is in review.',
            permission: 'daily_site_reports.view',
            columns: [
                ['key' => 'report_date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'site', 'label' => 'Site', 'type' => 'text'],
                ['key' => 'project', 'label' => 'Project', 'type' => 'text'],
                ['key' => 'prepared_by', 'label' => 'Prepared by', 'type' => 'text'],
                ['key' => 'total_manpower', 'label' => 'Manpower', 'type' => 'number'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'daily_site_reports.report_date',
                'to' => 'daily_site_reports.report_date',
                'site_id' => 'daily_site_reports.site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'employees.id',
                'status' => 'daily_site_reports.status',
            ],
            // `created_by` on this table points at **users**, not at
            // employees, so the join walks `users -> employees.user_id`
            // and falls back to the account name when a person running a
            // site has no employee record (a contractor account, a
            // freshly-created supervisor). An INNER join here would make
            // their reports vanish from a report about reports.
            builder: fn () => DailySiteReport::query()
                ->leftJoin('users', 'users.id', '=', 'daily_site_reports.created_by')
                ->leftJoin('employees', 'employees.user_id', '=', 'daily_site_reports.created_by')
                ->leftJoin('sites', 'sites.id', '=', 'daily_site_reports.site_id')
                ->leftJoin('projects', 'projects.id', '=', 'daily_site_reports.project_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw(sprintf(
                    'daily_site_reports.report_date, COALESCE(sites.name, %s) as site, COALESCE(projects.name, %s) as project, COALESCE(%s, users.name, %s) as prepared_by, daily_site_reports.total_manpower, daily_site_reports.status',
                    "''",
                    "''",
                    self::NAME,
                    "''",
                )),
            orderBy: 'daily_site_reports.report_date, daily_site_reports.id',
        );
    }

    private static function employeesDirectory(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'employees.directory',
            title: 'Employee directory',
            description: 'Who works here: code, name, department, designation, home site and employment status. No salary.',
            permission: 'employees.view',
            columns: [
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'designation', 'label' => 'Designation', 'type' => 'text'],
                ['key' => 'site', 'label' => 'Site', 'type' => 'text'],
                ['key' => 'joining_date', 'label' => 'Joined', 'type' => 'date'],
                ['key' => 'employment_type', 'label' => 'Type', 'type' => 'text'],
                ['key' => 'employment_status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'employees.joining_date',
                'to' => 'employees.joining_date',
                'site_id' => 'employees.primary_site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'employees.id',
                'status' => 'employees.employment_status',
            ],
            builder: fn () => Employee::query()
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->leftJoin('designations', 'designations.id', '=', 'employees.designation_id')
                ->leftJoin('sites', 'sites.id', '=', 'employees.primary_site_id')
                ->selectRaw(sprintf(
                    'employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, COALESCE(designations.name, %s) as designation, COALESCE(sites.name, %s) as site, employees.joining_date, employees.employment_type, employees.employment_status',
                    self::NAME,
                    "''",
                    "''",
                    "''",
                )),
            orderBy: 'employees.employee_code, employees.id',
        );
    }

    private static function documentsExpiry(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'documents.expiry',
            title: 'Document expiry register',
            description: 'Every document with an expiry date, so a visa or a card that is about to lapse is a row rather than a surprise.',
            permission: 'documents.expiry.view',
            columns: [
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'document_type', 'label' => 'Document', 'type' => 'text'],
                ['key' => 'document_number', 'label' => 'Number', 'type' => 'text'],
                ['key' => 'issue_date', 'label' => 'Issued', 'type' => 'date'],
                ['key' => 'expiry_date', 'label' => 'Expires', 'type' => 'date'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'employee_documents.expiry_date',
                'to' => 'employee_documents.expiry_date',
                'site_id' => 'employees.primary_site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'employee_documents.employee_id',
                'status' => 'employee_documents.status',
            ],
            builder: fn () => EmployeeDocument::query()
                ->join('employees', 'employees.id', '=', 'employee_documents.employee_id')
                ->leftJoin('document_types', 'document_types.id', '=', 'employee_documents.document_type_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw(sprintf(
                    'employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, COALESCE(document_types.name, %s) as document_type, employee_documents.document_number, employee_documents.issue_date, employee_documents.expiry_date, employee_documents.status',
                    self::NAME,
                    "''",
                    "''",
                )),
            orderBy: 'employee_documents.expiry_date, employee_documents.id',
        );
    }

    private static function trainingExpiry(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'training.expiry',
            title: 'Training expiry register',
            description: 'Training records whose certificate lapses, with the program and the date it stops counting.',
            permission: 'training.expiry.view',
            columns: [
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'training', 'label' => 'Training', 'type' => 'text'],
                ['key' => 'training_date', 'label' => 'Taken on', 'type' => 'date'],
                ['key' => 'certificate_expiry_date', 'label' => 'Certificate expires', 'type' => 'date'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'employee_trainings.certificate_expiry_date',
                'to' => 'employee_trainings.certificate_expiry_date',
                'site_id' => 'employees.primary_site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'employee_trainings.employee_id',
                'status' => 'employee_trainings.status',
            ],
            builder: fn () => EmployeeTraining::query()
                ->join('employees', 'employees.id', '=', 'employee_trainings.employee_id')
                ->leftJoin('training_programs', 'training_programs.id', '=', 'employee_trainings.training_program_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw(sprintf(
                    'employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, COALESCE(training_programs.name, %s) as training, employee_trainings.training_date, employee_trainings.certificate_expiry_date, employee_trainings.status',
                    self::NAME,
                    "''",
                    "''",
                )),
            orderBy: 'employee_trainings.certificate_expiry_date, employee_trainings.id',
        );
    }

    private static function expensesClaims(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'expenses.claims',
            title: 'Expense claims',
            description: 'Every claim with its category, amount and where it got to — the same rows the approvals screen shows.',
            permission: 'expenses.view',
            columns: [
                ['key' => 'expense_date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'text'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
                ['key' => 'currency', 'label' => 'Currency', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'expenses.expense_date',
                'to' => 'expenses.expense_date',
                'site_id' => 'expenses.site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'expenses.employee_id',
                'status' => 'expenses.status',
            ],
            builder: fn () => Expense::query()
                ->join('employees', 'employees.id', '=', 'expenses.employee_id')
                ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw(sprintf(
                    'expenses.expense_date, employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, COALESCE(expense_categories.name, %s) as category, expenses.amount, expenses.currency, expenses.status',
                    self::NAME,
                    "''",
                    "''",
                )),
            orderBy: 'expenses.expense_date, expenses.id',
        );
    }

    private static function loansOutstanding(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'loans.outstanding',
            title: 'Loans and advances',
            description: 'Principal, instalment and what is still owed on every loan.',
            permission: 'loans.view',
            columns: [
                ['key' => 'reference', 'label' => 'Reference', 'type' => 'text'],
                ['key' => 'employee_code', 'label' => 'Employee code', 'type' => 'text'],
                ['key' => 'employee_name', 'label' => 'Employee', 'type' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'type' => 'text'],
                ['key' => 'loan_type', 'label' => 'Type', 'type' => 'text'],
                ['key' => 'start_date', 'label' => 'Started', 'type' => 'date'],
                ['key' => 'principal_amount', 'label' => 'Principal', 'type' => 'money'],
                ['key' => 'outstanding_balance', 'label' => 'Outstanding', 'type' => 'money'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'loans.start_date',
                'to' => 'loans.start_date',
                'site_id' => 'employees.primary_site_id',
                'department_id' => 'employees.department_id',
                'employee_id' => 'loans.employee_id',
                'status' => 'loans.status',
            ],
            builder: fn () => Loan::query()
                ->join('employees', 'employees.id', '=', 'loans.employee_id')
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw(sprintf(
                    'loans.reference, employees.employee_code, %s as employee_name, COALESCE(departments.name, %s) as department, loans.loan_type, loans.start_date, loans.principal_amount, loans.outstanding_balance, loans.status',
                    self::NAME,
                    "''",
                )),
            orderBy: 'loans.start_date, loans.id',
        );
    }

    private static function assetsRegister(): ReportDefinition
    {
        return new ReportDefinition(
            key: 'assets.register',
            title: 'Asset register',
            description: 'What the company owns: code, type, serial, what it cost and whether it is out with somebody.',
            permission: 'assets.view',
            columns: [
                ['key' => 'asset_code', 'label' => 'Code', 'type' => 'text'],
                ['key' => 'name', 'label' => 'Asset', 'type' => 'text'],
                ['key' => 'asset_type', 'label' => 'Type', 'type' => 'text'],
                ['key' => 'serial_number', 'label' => 'Serial', 'type' => 'text'],
                ['key' => 'purchase_date', 'label' => 'Purchased', 'type' => 'date'],
                ['key' => 'purchase_cost', 'label' => 'Cost', 'type' => 'money'],
                ['key' => 'current_condition', 'label' => 'Condition', 'type' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ],
            filterColumns: [
                'from' => 'assets.purchase_date',
                'to' => 'assets.purchase_date',
                // An asset is not *at* a site — it is assigned to a person,
                // and it is unassigned whenever nobody holds it. There is
                // no column here that could answer `site_id` or
                // `department_id`, so both are refused (ReportFilters)
                // rather than quietly ignored: a filter that returns the
                // full table while claiming to narrow it is a report that
                // lies about what it is showing.
                'site_id' => null,
                'department_id' => null,
                // Answered through the hand-over history rather than a
                // join, because a laptop issued twice must appear **once**.
                // An INNER join on assignments would print it as many times
                // as it has ever changed hands, and the register would then
                // disagree with the asset screen it is a copy of.
                'employee_id' => fn ($query, string $value) => $query->whereExists(
                    fn ($sub) => $sub->selectRaw('1')
                        ->from('asset_assignments')
                        ->whereColumn('asset_assignments.asset_id', 'assets.id')
                        ->where('asset_assignments.employee_id', $value),
                ),
                'status' => 'assets.status',
            ],
            builder: fn () => Asset::query()
                ->leftJoin('asset_types', 'asset_types.id', '=', 'assets.asset_type_id')
                ->selectRaw(sprintf(
                    'assets.asset_code, assets.name, COALESCE(asset_types.name, %s) as asset_type, COALESCE(assets.serial_number, %s) as serial_number, assets.purchase_date, assets.purchase_cost, assets.current_condition, assets.status',
                    "''",
                    "''",
                )),
            orderBy: 'assets.asset_code, assets.id',
        );
    }
}
