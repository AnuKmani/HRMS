<?php

namespace App\Support;

use App\Models\Allowance;
use App\Models\Attendance;
use App\Models\DailySiteReport;
use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\SalaryCertificateRequest;
use App\Models\Site;
use App\Models\SiteActivityReport;
use App\Models\SiteVisit;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Row-level visibility, in one place.
 *
 * Every policy and every list query asks *this* class rather than
 * re-deriving the rule, because the two answers have to agree. A policy that
 * refused a single record while the index happily returned it would be no
 * boundary at all — the collection is the easier endpoint to hit.
 *
 * The rule itself is configured in config/hrms.php, so narrowing a role there
 * narrows both the collection and the individual check on the next deploy.
 *
 * Design note — fail toward the permission, not toward silence: a role that
 * is not listed reads everything its `*.view` permission already admits. The
 * alternative is an unlisted custom role quietly seeing an empty list, which
 * reads as "the system is broken" rather than "you are not authorised", and
 * produces a support ticket instead of a 403.
 */
final class Visibility
{
    /* ------------------------------------------------------------- roles */

    /**
     * Is this user's employee access narrowed to what they run?
     */
    public static function employeesAreScopedFor(User $user): bool
    {
        return $user->hasAnyRole((array) config('hrms.visibility.employee', []));
    }

    /**
     * Is this user's project/site access narrowed to what they run?
     */
    public static function projectsAndSitesAreScopedFor(User $user): bool
    {
        return $user->hasAnyRole((array) config('hrms.visibility.project', []));
    }

    /* ---------------------------------------------------------- employee */

    /**
     * Restrict an employee query to what this user may see.
     *
     * Own record is always included: being able to read yourself is not a
     * privilege anyone grants you, it is the absence of one.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function employeesFor(Builder $query, User $user)
    {
        if (! self::employeesAreScopedFor($user)) {
            return $query;
        }

        $own = $user->employee?->id;

        if ($own === null) {
            // A scoped role with no HR record runs nothing, and therefore has
            // no workforce. Failing closed here is correct — but the own-record
            // clause is moot because there is no own record to include.
            return $query->whereRaw('1 = 0');
        }

        $managedProjects = self::scopedProjectIds($user);
        $managedSites = self::scopedSiteIds($user);

        return $query->where(function ($q) use ($own, $managedProjects, $managedSites) {
            $q->where('employees.id', $own);

            if ($managedProjects->isNotEmpty()) {
                // orWhereIn, not `orWhere(..., 'in', ...)`: `where()` does not
                // accept 'in' as an operator, would silently re-read it as a
                // *value* ('project_id = "in"'), and the row would simply
                // never match. A scope that quietly excludes everyone it is
                // meant to include is worse than one that throws.
                $q->orWhereIn('employees.primary_project_id', $managedProjects);
            }

            if ($managedSites->isNotEmpty()) {
                $q->orWhereIn('employees.primary_site_id', $managedSites);
            }

            // Only when there is something to manage. With an empty scope the
            // inner `where()` compiles to *no* predicate at all, so the
            // EXISTS degenerates to "this employee has any assignment row" —
            // a scope that silently widens to the whole directory instead of
            // collapsing to the user's own records.
            if ($managedProjects->isNotEmpty() || $managedSites->isNotEmpty()) {
                $q->orWhereExists(function ($sub) use ($managedProjects, $managedSites) {
                    $sub->selectRaw('1')
                        ->from('employee_site_assignments')
                        ->whereColumn('employee_site_assignments.employee_id', 'employees.id');

                    $sub->where(function ($inner) use ($managedProjects, $managedSites) {
                        if ($managedProjects->isNotEmpty()) {
                            $inner->whereIn('employee_site_assignments.project_id', $managedProjects);
                        }

                        if ($managedSites->isNotEmpty()) {
                            $inner->orWhereIn('employee_site_assignments.site_id', $managedSites);
                        }
                    });
                });
            }
        });
    }

    /**
     * Does this one employee fall inside what the user may see?
     *
     * Same rule as employeesFor(), evaluated against a row rather than a
     * query, so `show` cannot answer differently from `index`.
     */
    public static function employeeIsVisible(User $user, Employee $employee): bool
    {
        if ($user->employee?->id === $employee->id) {
            return true;
        }

        if (! self::employeesAreScopedFor($user)) {
            return true;
        }

        $managedProjects = self::scopedProjectIds($user);
        $managedSites = self::scopedSiteIds($user);

        if ($managedProjects->contains($employee->primary_project_id)
            || $managedSites->contains($employee->primary_site_id)) {
            return true;
        }

        if ($managedProjects->isEmpty() && $managedSites->isEmpty()) {
            return false;
        }

        return $employee->siteAssignments()
            ->where(function ($q) use ($managedProjects, $managedSites) {
                if ($managedProjects->isNotEmpty()) {
                    $q->whereIn('project_id', $managedProjects);
                }

                if ($managedSites->isNotEmpty()) {
                    $q->orWhereIn('site_id', $managedSites);
                }
            })
            ->exists();
    }

    /* ------------------------------------------------- projects and sites */

    /**
     * @param  Builder<Project>|Builder<Site>  $query
     * @return Builder<Project>|Builder<Site>
     */
    public static function projectsAndSitesFor(Builder $query, User $user)
    {
        if (! self::projectsAndSitesAreScopedFor($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($query->getModel() instanceof Site) {
            return $query->where(function ($q) use ($employeeId) {
                $q->where('sites.site_manager_id', $employeeId)
                    ->orWhere('sites.site_supervisor_id', $employeeId);
            });
        }

        // A project is visible when they run it, or when it owns a site they
        // run — otherwise a supervisor would see the site and not the project
        // the site hangs off, which is a stranger boundary than either alone.
        return $query->where(function ($q) use ($employeeId) {
            $q->where('projects.project_manager_id', $employeeId)
                ->orWhereExists(function ($sub) use ($employeeId) {
                    $sub->selectRaw('1')
                        ->from('sites')
                        ->whereColumn('sites.project_id', 'projects.id')
                        ->where(function ($inner) use ($employeeId) {
                            $inner->where('sites.site_manager_id', $employeeId)
                                ->orWhere('sites.site_supervisor_id', $employeeId);
                        });
                });
        });
    }

    public static function projectIsVisible(User $user, Project $project): bool
    {
        if (! self::projectsAndSitesAreScopedFor($user)) {
            return true;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return false;
        }

        if ($project->project_manager_id === $employeeId) {
            return true;
        }

        return Site::query()
            ->where('project_id', $project->id)
            ->where(function ($q) use ($employeeId) {
                $q->where('site_manager_id', $employeeId)
                    ->orWhere('site_supervisor_id', $employeeId);
            })
            ->exists();
    }

    public static function siteIsVisible(User $user, Site $site): bool
    {
        if (! self::projectsAndSitesAreScopedFor($user)) {
            return true;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return false;
        }

        return $site->site_manager_id === $employeeId
            || $site->site_supervisor_id === $employeeId;
    }

    /* -------------------------------------------------------- assignments */

    /**
     * Restrict an assignment query to what this user may see.
     *
     * Scope follows the site/project rather than the employee, and the user's
     * own rows are always included — a supervisor who moved on should still
     * be able to read the posting that is theirs.
     *
     * @param  Builder<EmployeeSiteAssignment>  $query
     * @return Builder<EmployeeSiteAssignment>
     */
    public static function assignmentsFor(Builder $query, User $user)
    {
        if (! self::projectsAndSitesAreScopedFor($user)) {
            return $query;
        }

        $projects = self::scopedProjectIds($user);
        $sites = self::scopedSiteIds($user);
        $own = $user->employee?->id;

        if ($projects->isEmpty() && $sites->isEmpty() && $own === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($projects, $sites, $own) {
            if ($own !== null) {
                $q->where('employee_site_assignments.employee_id', $own);
            }

            if ($projects->isNotEmpty()) {
                $q->orWhereIn('employee_site_assignments.project_id', $projects);
            }

            if ($sites->isNotEmpty()) {
                $q->orWhereIn('employee_site_assignments.site_id', $sites);
            }
        });
    }

    /**
     * Does this one assignment fall inside what the user may see?
     */
    public static function assignmentIsVisible(User $user, EmployeeSiteAssignment $assignment): bool
    {
        if ($user->employee?->id === $assignment->employee_id) {
            return true;
        }

        if (! self::projectsAndSitesAreScopedFor($user)) {
            return true;
        }

        return self::scopedSiteIds($user)->contains($assignment->site_id)
            || self::scopedProjectIds($user)->contains($assignment->project_id);
    }

    /* ---------------------------------------------------------- attendance */

    /**
     * Is this user's attendance access narrowed to the projects/sites they
     * run?
     */
    public static function attendanceIsScopedFor(User $user): bool
    {
        return $user->hasAnyRole((array) config('hrms.visibility.attendance', []));
    }

    /**
     * May this user read somebody *else's* attendance?
     *
     * The one place the answer is decided, so AttendancePolicy and the
     * attendance queries cannot drift apart — a policy that said no while
     * the index said yes would be no boundary at all.
     *
     * Fails CLOSED, unlike the module visibility rules above, and for a
     * different reason. Those answer "which rows of a module may this role
     * open?" for roles that already hold the module's permission; this one
     * also decides whether an ordinary employee — who holds nothing but
     * `attendance.view` for their own records — may see the workforce's.
     * Falling back to "everything" there would publish every check-in in the
     * company to every phone in it.
     *
     * Three ways in, and no fourth:
     *   - a role listed in config('hrms.visibility.attendance') — scoped;
     *   - `attendance.manage`, the explicit "you run attendance" grant;
     *   - `employees.view`, i.e. already trusted with the workforce itself —
     *     scoped the same way the employee directory is.
     */
    public static function mayViewOthersAttendance(User $user): bool
    {
        if (! $user->can('attendance.view')) {
            return false;
        }

        if (self::attendanceIsScopedFor($user)) {
            return true;
        }

        return $user->can('attendance.manage') || $user->can('employees.view');
    }

    /**
     * Restrict an attendance (or site visit) query to what this user may see.
     *
     * @param  Builder<Attendance>|Builder<SiteVisit>  $query
     * @return Builder<Attendance>|Builder<SiteVisit>
     */
    public static function attendanceFor(Builder $query, User $user)
    {
        $table = $query->getModel()->getTable();

        if (! self::mayViewOthersAttendance($user)) {
            // Fails closed: no employee record means no rows at all, not all
            // of them. `0` rather than `whereRaw('1 = 0')` keeps the query
            // indexable and reads as what it means.
            return $query->where($table.'.employee_id', $user->employee?->id ?? 0);
        }

        if (! self::attendanceIsScopedFor($user)) {
            return $query;
        }

        $own = $user->employee?->id;
        $projects = self::scopedProjectIds($user);
        $sites = self::scopedSiteIds($user);

        if ($own === null && $projects->isEmpty() && $sites->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($table, $own, $projects, $sites) {
            if ($own !== null) {
                $q->where($table.'.employee_id', $own);
            }

            if ($projects->isNotEmpty()) {
                $q->orWhereIn($table.'.project_id', $projects);
            }

            if ($sites->isNotEmpty()) {
                $q->orWhereIn($table.'.site_id', $sites);
            }

            // A person on their managed workforce who checked in somewhere
            // else today — the row's own site is out of scope, the person is
            // not. Same rule employeesFor() applies to the directory.
            //
            // Guarded for the same reason: with an empty scope the inner
            // `where()` compiles to no predicate, the EXISTS becomes "this
            // employee exists at all", and every row matches. A listed
            // overseer who manages nothing must read their own records and
            // nothing else — not everyone's.
            if ($projects->isNotEmpty() || $sites->isNotEmpty()) {
                $q->orWhereExists(function ($sub) use ($table, $projects, $sites) {
                    $sub->selectRaw('1')
                        ->from('employees')
                        ->whereColumn('employees.id', $table.'.employee_id')
                        ->where(function ($inner) use ($projects, $sites) {
                            if ($projects->isNotEmpty()) {
                                $inner->whereIn('employees.primary_project_id', $projects);
                            }

                            if ($sites->isNotEmpty()) {
                                $inner->orWhereIn('employees.primary_site_id', $sites);
                            }
                        });
                });
            }
        });
    }

    /**
     * Does this one attendance/site-visit row fall inside what the user may
     * see? Same rule as attendanceFor(), evaluated against a row.
     */
    public static function attendanceIsVisible(User $user, Attendance|SiteVisit $record): bool
    {
        return self::personRowIsVisible(
            $user,
            $record->employee_id,
            $record->project_id,
            $record->site_id,
            self::mayViewOthersAttendance($user),
        );
    }

    /* --------------------------------------------------------------- leave */

    /**
     * May this user read somebody *else's* leave?
     *
     * The same shape as mayViewOthersAttendance(), and for the same reason:
     * `leave.view` is what lets an Employee open their own history, so it
     * cannot also mean "read the company's". Three ways in, and no fourth —
     * a listed overseer, `leave.manage`, or `employees.view`.
     *
     * The scope list is the *attendance* one on purpose. Leave, timesheets and
     * overtime are all facts about a person's working day, they follow the
     * same workforce, and keeping one list means narrowing a role once
     * narrows all four rather than three out of four.
     */
    public static function mayViewOthersLeave(User $user): bool
    {
        if (! $user->can('leave.view')) {
            return false;
        }

        if (self::attendanceIsScopedFor($user)) {
            return true;
        }

        return $user->can('leave.manage') || $user->can('employees.view');
    }

    /**
     * Restrict a leave query to what this user may see.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public static function leaveFor(Builder $query, User $user)
    {
        if (! self::mayViewOthersLeave($user)) {
            // Fails closed, exactly as attendance does: `0` rather than a
            // predicate that matches nothing keeps it indexable and reads as
            // what it means — no employee record, no rows.
            return $query->where('leave_requests.employee_id', $user->employee?->id ?? 0);
        }

        // The directory's own narrowing, reused rather than re-derived: the
        // question "whose people are these?" has one answer in this codebase.
        //
        // Two things make this a whereHas rather than a direct call.
        // employeesFor() qualifies its columns with `employees.` — it is
        // written for a query *on* the directory — so running it against
        // `leave_requests` would compile an `employees.id` predicate no join
        // supplies and fail at execution time rather than where somebody
        // could see why. And an unscoped reader needs no narrowing at all:
        // the sub-query would cost a join to assert something already true.
        if (! self::employeesAreScopedFor($user)) {
            return $query;
        }

        return $query->whereHas('employee', fn ($q) => self::employeesFor($q, $user));
    }

    /**
     * Does this one leave request fall inside the user's view?
     */
    public static function leaveIsVisible(User $user, LeaveRequest $leave): bool
    {
        if ($user->employee?->id === $leave->employee_id) {
            return true;
        }

        if (! self::mayViewOthersLeave($user)) {
            return false;
        }

        $employee = Employee::query()->find($leave->employee_id);

        return $employee !== null && self::employeeIsVisible($user, $employee);
    }

    /* ---------------------------------------------------------- timesheets */

    /**
     * Restrict a timesheet query to what this user may see.
     *
     * attendanceFor() is reused deliberately — a timesheet is a projection of
     * an attendance day, so it answers to attendance's scope rule rather than
     * inventing a second one that could disagree with it.
     *
     * @param  Builder<Timesheet>  $query
     * @return Builder<Timesheet>
     */
    public static function timesheetsFor(Builder $query, User $user)
    {
        return self::attendanceFor($query, $user);
    }

    public static function timesheetIsVisible(User $user, Timesheet $record): bool
    {
        return self::personRowIsVisible(
            $user,
            $record->employee_id,
            $record->project_id,
            $record->site_id,
            // Exactly what the list asks, not a second question about the
            // same thing.
            //
            // The tempting answer here is `$user->can('timesheets.view')`,
            // and it is wrong in a way that only shows up as an
            // inconsistency: that permission is a *coarse* gate — it says
            // this module is open to you, not that other people's days are.
            // An Employee holds it, so passing it as `mayViewOthers` would
            // hand every employee `show` on every colleague's timesheet while
            // `index` dutifully returned one row each. The collection is the
            // easier endpoint to hit, so the two must ask one question, and
            // the question is attendance's: this is a projection of an
            // attendance day and it answers to attendance's scope rule.
            self::mayViewOthersAttendance($user),
        );
    }

    /* ------------------------------------------------------------ overtime */

    /**
     * May this user read somebody *else's* overtime? Same three ways in as
     * attendance and leave, with `overtime.manage` as the explicit grant.
     */
    public static function mayViewOthersOvertime(User $user): bool
    {
        if (! $user->can('overtime.view')) {
            return false;
        }

        if (self::attendanceIsScopedFor($user)) {
            return true;
        }

        return $user->can('overtime.manage') || $user->can('employees.view');
    }

    /**
     * @param  Builder<OvertimeRequest>  $query
     * @return Builder<OvertimeRequest>
     */
    public static function overtimeFor(Builder $query, User $user)
    {
        if (! self::mayViewOthersOvertime($user)) {
            return $query->where('overtime_requests.employee_id', $user->employee?->id ?? 0);
        }

        if (! self::attendanceIsScopedFor($user)) {
            return $query;
        }

        // employeesFor() narrows on `employees.*`, so the scope has to be
        // expressed as a sub-query against that table. Leaving it to run
        // against `overtime_requests` would compare columns that do not exist
        // there and silently return nothing.
        return $query->whereHas('employee', fn ($q) => self::employeesFor($q, $user));
    }

    public static function overtimeIsVisible(User $user, OvertimeRequest $record): bool
    {
        if ($user->employee?->id === $record->employee_id) {
            return true;
        }

        if (! self::mayViewOthersOvertime($user)) {
            return false;
        }

        if (! self::attendanceIsScopedFor($user)) {
            return true;
        }

        return self::personRowIsVisible(
            $user,
            $record->employee_id,
            $record->project_id,
            $record->site_id,
            true,
        );
    }

    /**
     * May this user read somebody *else's* leave balance?
     *
     * The shape of mayViewOthersLeave(), with balance's own two permissions
     * swapped in. It exists as a separate answer rather than as
     * `mayViewOthersLeave()` reused, because the coarse question really is
     * different — `leave.balance.view` is what admits you to the pot and
     * `leave.balance.manage` is the explicit "you may correct a number" grant
     * — and reusing `leave.view` here would make an account that somehow held
     * one but not the other behave differently depending on which screen it
     * opened.
     *
     * Fails CLOSED for the same reason leave does: an ordinary employee holds
     * `leave.balance.view` for their own summary, so it cannot also mean
     * "the company's".
     *
     * Three ways in, and no fourth — a listed overseer, `leave.balance.manage`,
     * or `employees.view`.
     */
    public static function mayViewOthersBalances(User $user): bool
    {
        if (! $user->can('leave.balance.view')) {
            return false;
        }

        if (self::attendanceIsScopedFor($user)) {
            return true;
        }

        return $user->can('leave.balance.manage') || $user->can('employees.view');
    }

    /**
     * The sites this account is actually attached to.
     *
     * Active postings, their own primary site, and the sites they run — three
     * sources because they answer three different questions: where they are
     * posted, where their contract puts them, and what they are responsible
     * for.
     *
     * Two modules read this set and neither is named after the other: the
     * holiday calendar uses it to place site-specific days off, and site
     * reporting uses it twice over — to decide which site a person may file
     * a report about, and which reports they may then read back. Those are
     * the same physical question ("is this their site?"), so they get the
     * same answer from one method rather than two copies that could drift.
     *
     * Here rather than on a controller because the same set has to be read
     * by the policy when a single record is opened by id — a `show` that
     * answered "yes" while `index` hid the row would let the narrower of the
     * two be found first.
     *
     * @return Collection<int, int>
     */
    public static function attachedSiteIds(User $user): Collection
    {
        $employee = $user->employee;

        if ($employee === null) {
            return self::scopedSiteIds($user);
        }

        $siteIds = EmployeeSiteAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('status', EmployeeSiteAssignment::STATUS_ACTIVE)
            ->pluck('site_id');

        $siteIds->push($employee->primary_site_id);
        $siteIds->push(...self::scopedSiteIds($user)->all());

        return $siteIds->filter()->unique()->values();
    }

    /**
     * Does this one holiday fall inside the calendar this reader may see?
     *
     * The row-level twin of attachedSiteIds(), so `GET /holidays/{id}` cannot
     * answer "yes" to a site day that `GET /holidays` deliberately hid. Public
     * and company days are visible to everybody, because the reason
     * HolidayPolicy grants `viewAny()` to every signed-in account applies to
     * them one at a time too.
     */
    public static function holidayIsVisible(User $user, Holiday $holiday): bool
    {
        if ($holiday->type !== Holiday::TYPE_SITE) {
            return true;
        }

        // Configuring a site holiday you cannot then read would be a strange
        // kind of edit, so the whole calendar is open to whoever may write it.
        if ($user->can('holidays.manage')) {
            return true;
        }

        return $holiday->site_id !== null
            && self::attachedSiteIds($user)->contains($holiday->site_id);
    }

    /* -------------------------------------------------------- site reports */

    /**
     * May this user read somebody *else's* site activity report?
     *
     * Three ways in, exactly as attendance — a listed field overseer, an
     * explicit reporting grant, or `employees.view` — and it fails CLOSED
     * for the reason attendance does: an ordinary employee holds
     * `site_activity_reports.view` for their *own* notes, so the permission
     * cannot also mean "the company's".
     *
     * The middle grant here is `daily_site_reports.manage` rather than a
     * `site_activity_reports.manage` that does not exist: this module has
     * three permissions (view / create / update) because it has no approval
     * step, and the role that *does* run reporting — Project Manager, and
     * Super Admin through `*` — is the natural holder of the one override
     * that exists.
     */
    public static function mayViewOthersSiteActivityReports(User $user): bool
    {
        if (! $user->can('site_activity_reports.view')) {
            return false;
        }

        if (self::attendanceIsScopedFor($user)) {
            return true;
        }

        return $user->can('daily_site_reports.manage') || $user->can('employees.view');
    }

    /**
     * May this user read somebody *else's* official daily site report?
     *
     * Same three doors as the activity report with `daily_site_reports.view`
     * and `daily_site_reports.manage` swapped in. An Employee holds neither,
     * so their answer to "whose daily reports may I see?" is "none" — the
     * official site-day record is prepared by a supervisor and read by
     * back-office, and handing the entire company's site-days to every phone
     * in it because a coarse permission said `view` would be a leak the
     * permission was never meant to grant.
     */
    public static function mayViewOthersDailySiteReports(User $user): bool
    {
        if (! $user->can('daily_site_reports.view')) {
            return false;
        }

        if (self::attendanceIsScopedFor($user)) {
            return true;
        }

        return $user->can('daily_site_reports.manage') || $user->can('employees.view');
    }

    /**
     * May a report be filed about this site at all?
     *
     * Asked on create, on update and again at submit, because the site a
     * report points at is the one thing a client sends that says *where*
     * somebody claims to have been, and "you may only report where you are
     * placed" is worthless if it is checked once against the first draft.
     *
     * Three ways in, and no fourth:
     *  - the site is one of theirs — an active posting, their primary site,
     *    or a site they manage or supervise (attachedSiteIds);
     *  - a project they personally run (scopedProjectIds) — a Project
     *    Manager is on every site of the project by definition and on none
     *    of them by posting;
     *  - `daily_site_reports.manage`, the explicit "you run reporting"
     *    grant, which is what lets Super Admin file from a desk.
     *
     * Deliberately does NOT fall open for roles that are unscoped. The
     * class's general convention — an unlisted role reads what its
     * permission admits — is right for *reading* and wrong for *filing*:
     * there is no permission-only answer to "is this their site?".
     */
    public static function mayReportAt(User $user, Site $site): bool
    {
        if (self::attachedSiteIds($user)->contains($site->id)) {
            return true;
        }

        if (self::scopedProjectIds($user)->contains($site->project_id)) {
            return true;
        }

        return $user->can('daily_site_reports.manage');
    }

    /**
     * Restrict a site activity report query to what this user may see.
     *
     * @param  Builder<SiteActivityReport>  $query
     * @return Builder<SiteActivityReport>
     */
    public static function siteActivityReportsFor(Builder $query, User $user)
    {
        if (! self::mayViewOthersSiteActivityReports($user)) {
            // Fails closed, `0` rather than `whereRaw('1 = 0')` for the same
            // reason attendanceFor() uses it: indexable, and honest about
            // meaning "no employee record, therefore no rows".
            return $query->where('site_activity_reports.employee_id', $user->employee?->id ?? 0);
        }

        if (! self::attendanceIsScopedFor($user)) {
            return $query;
        }

        $own = $user->employee?->id;
        $sites = self::attachedSiteIds($user);
        $projects = self::scopedProjectIds($user);

        if ($own === null && $sites->isEmpty() && $projects->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($own, $sites, $projects) {
            if ($own !== null) {
                $q->where('site_activity_reports.employee_id', $own);
            }

            if ($sites->isNotEmpty()) {
                $q->orWhereIn('site_activity_reports.site_id', $sites);
            }

            if ($projects->isNotEmpty()) {
                $q->orWhereIn('site_activity_reports.project_id', $projects);
            }
        });
    }

    /**
     * Does this one activity report fall inside what this reader may see?
     *
     * The row-level twin of siteActivityReportsFor().
     */
    public static function siteActivityReportIsVisible(User $user, SiteActivityReport $report): bool
    {
        if ($user->employee?->id === $report->employee_id) {
            return true;
        }

        if (! self::mayViewOthersSiteActivityReports($user)) {
            return false;
        }

        if (! self::attendanceIsScopedFor($user)) {
            return true;
        }

        return self::attachedSiteIds($user)->contains($report->site_id)
            || self::scopedProjectIds($user)->contains($report->project_id);
    }

    /**
     * Restrict a daily site report query to what this user may see.
     *
     * @param  Builder<DailySiteReport>  $query
     * @return Builder<DailySiteReport>
     */
    public static function dailySiteReportsFor(Builder $query, User $user)
    {
        if (! self::mayViewOthersDailySiteReports($user)) {
            return $query->where('daily_site_reports.created_by', $user->id);
        }

        if (! self::attendanceIsScopedFor($user)) {
            return $query;
        }

        $sites = self::attachedSiteIds($user);
        $projects = self::scopedProjectIds($user);

        // Own rows first, and unconditionally: a scoped role that manages
        // nothing yet has still written reports, and a site-only scope would
        // delete the documents it prepared from its own list. The empty case
        // therefore needs no `1 = 0` — `created_by = ?` is already a real
        // predicate, unlike an empty `orWhereIn` group.
        return $query->where(function ($q) use ($user, $sites, $projects) {
            $q->where('daily_site_reports.created_by', $user->id);

            if ($sites->isNotEmpty()) {
                $q->orWhereIn('daily_site_reports.site_id', $sites);
            }

            if ($projects->isNotEmpty()) {
                $q->orWhereIn('daily_site_reports.project_id', $projects);
            }
        });
    }

    /**
     * Does this one daily site report fall inside what this reader may see?
     *
     * Own rows are always readable: a supervisor who prepared a report for a
     * site they are later moved off must not lose access to the document
     * they wrote, which is exactly what a site-only scope would do.
     */
    public static function dailySiteReportIsVisible(User $user, DailySiteReport $report): bool
    {
        if ($report->created_by === $user->id) {
            return true;
        }

        if (! self::mayViewOthersDailySiteReports($user)) {
            return false;
        }

        if (! self::attendanceIsScopedFor($user)) {
            return true;
        }

        return self::attachedSiteIds($user)->contains($report->site_id)
            || self::scopedProjectIds($user)->contains($report->project_id);
    }

    /**
     * The sites this account may actually file a report about, as a query.
     *
     * The list half of {@see self::mayReportAt()} — the two must agree, or a
     * picker would offer a site the store endpoint then refuses, which is
     * the most annoying way to be told "no": after the work is typed.
     *
     * A named endpoint needs this for a reason worth stating: an Employee
     * holds no `sites.view`, so `GET /sites` is 403 for exactly the role the
     * field-reporting form exists to serve. Offering `sites.view` to close
     * that gap would hand every phone in the company the whole site
     * directory with its coordinates; `site-activity-reports/reportable-sites`
     * answers the narrower question — *which sites may you write about* — and
     * needs no new permission to ask it.
     *
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    public static function reportableSitesFor(Builder $query, User $user)
    {
        // `daily_site_reports.manage` is the third way in on mayReportAt():
        // a holder of it may file anywhere, so narrowing would disagree with
        // the store endpoint they are about to call.
        if ($user->can('daily_site_reports.manage')) {
            return $query;
        }

        $sites = self::attachedSiteIds($user);
        $projects = self::scopedProjectIds($user);

        if ($sites->isEmpty() && $projects->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($sites, $projects) {
            if ($sites->isNotEmpty()) {
                $q->orWhereIn('id', $sites);
            }

            if ($projects->isNotEmpty()) {
                $q->orWhereIn('project_id', $projects);
            }
        });
    }

    /* ---------------------------------------------------- Phase 8: pay */

    /*
    | Payroll is the one module whose scoping rule is *not* the workforce
    | rule, and the difference matters.
    |
    | Attendance, leave, timesheets and overtime all follow "whose people
    | are these?" - a Project Manager reads their own project's rows because
    | they run that project. Payroll has no equivalent: nobody in this
    | system is *assigned* the company's salaries, they are **granted** them.
    | So the question here is a flat "is this a back-office reader?", and the
    | answer comes from permissions alone rather than from a visibility list
    | in config/hrms.php. Narrowing by project would be meaningless - a
    | manager does not pay their project differently - and would silently
    | give a Site Supervisor the salaries of everyone on a site they happen
    | to run, which is precisely the disclosure the spec rules out.
    |
    | The second difference: these queries fail closed against
    | `payroll.view`, not against a separate "may see others" permission, so
    | an Employee who holds `payroll.view` for their own slip is narrowed to
    | exactly one row rather than to an empty list.
    */

    /**
     * May this user read somebody *else's* payroll row?
     *
     * Two ways in and no third: `payroll.manage` (the role that prepares
     * pay) or `employees.salary.view` (the pre-existing "this role is
     * trusted with pay figures" grant - Super Admin, HR Admin, Payroll Admin
     * and Finance, and nobody else). Management deliberately holds neither,
     * which is what makes its `payroll.view` a window onto its own row rather
     * than onto the company's.
     */
    public static function mayViewOthersPayroll(User $user): bool
    {
        if (! $user->can('payroll.view')) {
            return false;
        }

        return $user->can('payroll.manage') || $user->can('employees.salary.view');
    }

    /**
     * @param  Builder<Payroll>  $query
     * @return Builder<Payroll>
     */
    public static function payrollFor(Builder $query, User $user)
    {
        if (! self::mayViewOthersPayroll($user)) {
            return $query->where('payrolls.employee_id', $user->employee?->id ?? 0);
        }

        return $query;
    }

    public static function payrollIsVisible(User $user, Payroll $payroll): bool
    {
        if ($user->employee?->id === $payroll->employee_id) {
            return true;
        }

        return self::mayViewOthersPayroll($user);
    }

    /**
     * May this user read somebody *else's* salary slip?
     *
     * Asked separately from payroll because the two grants are separate:
     * `salary_slips.view` is held by every role that should ever see a
     * payslip, and `salary_slips.manage` (or the underlying
     * `payroll.manage`) is what says *whose*. A slip is the same numbers as
     * the payroll row in document form, so it would be a strange boundary
     * that refused the row and handed over the PDF.
     */
    public static function mayViewOthersSalarySlips(User $user): bool
    {
        if (! $user->can('salary_slips.view')) {
            return false;
        }

        return $user->can('salary_slips.manage') || $user->can('payroll.manage');
    }

    /**
     * @param  Builder<Payroll>  $query
     * @return Builder<Payroll>
     */
    public static function salarySlipsFor(Builder $query, User $user)
    {
        if (! self::mayViewOthersSalarySlips($user)) {
            return $query->where('payrolls.employee_id', $user->employee?->id ?? 0);
        }

        return $query;
    }

    /**
     * May this user read somebody *else's* loan or salary advance?
     *
     * Fails closed with no `employees.view` fallback - unlike leave,
     * attendance and overtime, which all grant the override to a role that
     * already holds the directory. A loan says how much somebody owes, and
     * "you may see the staff list" is not a decision about that. The two ways
     * in are the two that *are* a decision about it: you may approve loans,
     * or you may maintain them.
     */
    public static function mayViewOthersLoans(User $user): bool
    {
        if (! $user->can('loans.view')) {
            return false;
        }

        return $user->can('loans.manage') || $user->can('loans.approve');
    }

    /**
     * @param  Builder<Loan>  $query
     * @return Builder<Loan>
     */
    public static function loansFor(Builder $query, User $user)
    {
        if (! self::mayViewOthersLoans($user)) {
            return $query->where('loans.employee_id', $user->employee?->id ?? 0);
        }

        return $query;
    }

    public static function loanIsVisible(User $user, Loan $loan): bool
    {
        if ($user->employee?->id === $loan->employee_id) {
            return true;
        }

        return self::mayViewOthersLoans($user);
    }

    /**
     * May this user read somebody *else's* salary certificate request?
     *
     * One way in: `salary_certificates.manage`, the decision-making grant.
     * The requester always keeps their own - including after approval,
     * because a document requested about yourself is yours whether or not
     * HR has signed it off yet.
     */
    public static function mayViewOthersSalaryCertificates(User $user): bool
    {
        if (! $user->can('salary_certificates.view')) {
            return false;
        }

        return $user->can('salary_certificates.manage');
    }

    /**
     * @param  Builder<SalaryCertificateRequest>  $query
     * @return Builder<SalaryCertificateRequest>
     */
    public static function salaryCertificateRequestsFor(Builder $query, User $user)
    {
        if (! self::mayViewOthersSalaryCertificates($user)) {
            return $query->where('salary_certificate_requests.employee_id', $user->employee?->id ?? 0);
        }

        return $query;
    }

    public static function salaryCertificateRequestIsVisible(
        User $user,
        SalaryCertificateRequest $request,
    ): bool {
        if ($user->employee?->id === $request->employee_id) {
            return true;
        }

        return self::mayViewOthersSalaryCertificates($user);
    }

    /**
     * Restrict an allowance query: everybody who prepares pay reads every
     * allowance, everybody else reads their own.
     *
     * @param  Builder<Allowance>  $query
     * @return Builder<Allowance>
     */
    public static function allowancesFor(Builder $query, User $user)
    {
        if ($user->can('payroll.manage')) {
            return $query;
        }

        return $query->where('allowances.employee_id', $user->employee?->id ?? 0);
    }

    public static function allowanceIsVisible(User $user, Allowance $allowance): bool
    {
        if ($user->employee?->id === $allowance->employee_id) {
            return true;
        }

        return $user->can('payroll.manage');
    }

    /**
     * Restrict an adjustment query. Same shape as allowances: `payroll.manage`
     * is the whole answer, and without it the rows you can reach are your
     * own.
     *
     * @param  Builder<PayrollAdjustment>  $query
     * @return Builder<PayrollAdjustment>
     */
    public static function payrollAdjustmentsFor(Builder $query, User $user)
    {
        if ($user->can('payroll.manage')) {
            return $query;
        }

        return $query->where('payroll_adjustments.employee_id', $user->employee?->id ?? 0);
    }

    public static function payrollAdjustmentIsVisible(
        User $user,
        PayrollAdjustment $adjustment,
    ): bool {
        if ($user->employee?->id === $adjustment->employee_id) {
            return true;
        }

        return $user->can('payroll.manage');
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The row-level rule for any record that knows its employee, project and
     * site — attendance, site visits, timesheets and overtime all reduce to
     * these three numbers.
     *
     * `$mayViewOthers` is passed in rather than re-derived, because the
     * coarse question differs per module ("attendance.view?" is not
     * "timesheets.view?") while the *scope* that follows it is the same
     * workforce rule for all of them.
     *
     * Split out so a collection query and the `show` endpoint's single-record
     * check cannot drift: a policy that answered "no" while the index
     * answered "yes" would be no boundary at all.
     */
    private static function personRowIsVisible(
        User $user,
        int $employeeId,
        ?int $projectId,
        ?int $siteId,
        bool $mayViewOthers,
    ): bool {
        if ($user->employee?->id === $employeeId) {
            return true;
        }

        if (! $mayViewOthers) {
            return false;
        }

        if (! self::attendanceIsScopedFor($user)) {
            return true;
        }

        if (self::scopedProjectIds($user)->contains($projectId)
            || self::scopedSiteIds($user)->contains($siteId)) {
            return true;
        }

        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            return false;
        }

        return self::scopedProjectIds($user)->contains($employee->primary_project_id)
            || self::scopedSiteIds($user)->contains($employee->primary_site_id);
    }

    /**
     * @return Collection<int, int>
     */
    public static function scopedProjectIds(User $user)
    {
        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return collect();
        }

        return Project::query()
            ->where('project_manager_id', $employeeId)
            ->pluck('id');
    }

    /**
     * @return Collection<int, int>
     */
    public static function scopedSiteIds(User $user)
    {
        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return collect();
        }

        return Site::query()
            ->where(function ($q) use ($employeeId) {
                $q->where('site_manager_id', $employeeId)
                    ->orWhere('site_supervisor_id', $employeeId);
            })
            ->pluck('id');
    }
}
