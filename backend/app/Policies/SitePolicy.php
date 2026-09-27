<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use App\Support\Visibility;

/**
 * Sites: the coarse permission, narrowed for field roles.
 *
 * A site has a manager and a supervisor, which is the natural boundary —
 * Site Supervisor and Site Engineer (config/hrms.php) see the sites they run
 * and no others. Everyone holding `projects.manage`/`sites.manage` reads the
 * whole collection.
 *
 * Nothing here mentions coordinates: where a site is and how wide its
 * geofence reaches are configuration, not authorization.
 */
class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sites.view');
    }

    public function view(User $user, Site $site): bool
    {
        if (! $user->can('sites.view')) {
            return false;
        }

        return Visibility::siteIsVisible($user, $site);
    }

    public function create(User $user): bool
    {
        return $user->can('sites.manage');
    }

    public function update(User $user, Site $site): bool
    {
        return $user->can('sites.manage')
            && Visibility::siteIsVisible($user, $site);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->can('sites.manage')
            && Visibility::siteIsVisible($user, $site);
    }
}
