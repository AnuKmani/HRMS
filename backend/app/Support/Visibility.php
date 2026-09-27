<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\EmployeeSiteAssignment;
use App\Models\Project;
use App\Models\Site;
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

    /* ------------------------------------------------------------ helpers */

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
