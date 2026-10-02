<?php

namespace App\Policies;

use App\Models\AssetAssignment;
use App\Models\User;
use App\Support\Visibility;

/**
 * One hand-over of one asset to one person.
 *
 * Read-only by design, and that is the whole policy. Nothing in this module
 * *creates* an assignment directly: AssetService::assign() writes the row
 * inside its own lock and is reached through AssetPolicy::assign() instead,
 * so there is no ability here that would hand out an asset, and nothing
 * that could be asked of a resource the service has not already settled.
 *
 * The two doors:
 *
 *  - `viewAny` is `assets.view`, the coarse gate every employee holds —
 *    otherwise nobody could see the laptop on their own desk.
 *  - `view` is {@see Visibility::assetAssignmentIsVisible()}: your own
 *    hand-overs, or `assets.history.view`. That is the cross-employee log —
 *    who has held anything, ever — and it is HR's and Management's because
 *    it is a control document, not a personal file.
 *
 * The distinction matters: `assets.view` alone gets you an asset's *own*
 * history through the asset's detail page, where the rows are yours; it does
 * not get you the log of a colleague's hand-overs. Two questions, two
 * permissions, asked separately because the two lists have different totals
 * and neither can be derived from the other.
 *
 * There is no `delete`. A closed hand-back is history — "who had this in
 * March?" has to still be answerable in November — and an assignment row
 * that could be removed is exactly how an audit trail stops being one.
 */
class AssetAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('assets.view');
    }

    public function view(User $user, AssetAssignment $assignment): bool
    {
        return Visibility::assetAssignmentIsVisible($user, $assignment);
    }

    /**
     * The cross-employee hand-over log.
     *
     * Its own ability rather than a flag on `viewAny`, for the reason
     * `training.expiry.view` has one: a holder of `assets.view` alone reads
     * their own rows through ownership, and the log of who else has held
     * anything is a different question with a different audience.
     */
    public function history(User $user): bool
    {
        return $user->can('assets.history.view');
    }
}
