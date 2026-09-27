<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\Visibility;

/**
 * Projects: the coarse permission, narrowed only for field roles.
 *
 * `projects.manage` is what makes a Project Manager a project manager — it
 * opens the module in full, so Project Manager is *not* in the scoped list
 * (config/hrms.php). Site Supervisor and Site Engineer hold `projects.view`
 * only, and see the projects that own a site they run; without that they
 * would be looking at a project list that has nothing to do with them.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        if (! $user->can('projects.view')) {
            return false;
        }

        return Visibility::projectIsVisible($user, $project);
    }

    public function create(User $user): bool
    {
        return $user->can('projects.manage');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('projects.manage')
            && Visibility::projectIsVisible($user, $project);
    }

    /**
     * Soft delete. Sites restrict against the FK (sites.project_id is
     * restrictOnDelete), so a project carrying sites cannot actually be
     * removed — the controller turns that into a clear 422 rather than a
     * database error.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.manage')
            && Visibility::projectIsVisible($user, $project);
    }
}
