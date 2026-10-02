<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Support\Visibility;

/**
 * One piece of company property: may this role see it, edit it, move it?
 *
 * The same "coarse gate plus a narrower row rule" shape employee documents
 * and training both use, and for the same reason each time: `assets.view`
 * has to be held by every employee — you cannot show somebody the laptop on
 * their desk behind a permission they do not have — which makes it worth
 * nothing on its own.
 *
 * So the row rule does the work. Without `assets.manage` you see the assets
 * you have actually been handed, current and past, and nothing of the pool.
 * With it you see everything.
 *
 * `create` / `update` are the master record. `assign` and `return` are the
 * two hand-over acts, each its own permission because they are different
 * people in practice. `changeStatus` — maintenance, damaged, lost, retired —
 * is `assets.manage`, and `purchase_cost` is not a policy question at all:
 * AssetResource withholds it from a reader without `assets.manage` before a
 * body is ever built.
 *
 * State (is this asset assignable, may it walk to that status, is there
 * something to return) is AssetService's answer and a 409 naming the state,
 * never this policy's 403. That split is the one every policy here keeps.
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('assets.view');
    }

    public function view(User $user, Asset $asset): bool
    {
        return Visibility::assetIsVisible($user, $asset);
    }

    public function create(User $user): bool
    {
        return $user->can('assets.create');
    }

    /**
     * Correct an asset's master record — its name, serial, notes.
     *
     * Condition and status are deliberately NOT part of an edit even when
     * this passes: AssetService refuses them there, because they change
     * through assign, return and changeStatus, each of which records who
     * and when. Accepting them in an update would be a back door past all
     * three.
     */
    public function update(User $user, Asset $asset): bool
    {
        if (! $user->can('assets.update')) {
            return false;
        }

        return Visibility::assetIsVisible($user, $asset);
    }

    /**
     * Hand an asset to somebody.
     *
     * `assets.assign` on top of the row rule, so an account that could see
     * only its own kit could never hand out somebody else's — and, because
     * `assets.view` alone narrows the pool, could not reach the pool at all.
     */
    public function assign(User $user, Asset $asset): bool
    {
        if (! $user->can('assets.assign')) {
            return false;
        }

        return Visibility::assetIsVisible($user, $asset);
    }

    /**
     * Take an asset back.
     *
     * Its own permission beside `assign`: in practice a site office hands
     * tools out while only HR writes them off, and splitting the two lets
     * that be expressed. Row-scoped for the same reason as assign — a
     * hand-back is an act *on* the asset, so you had better be able to see
     * it.
     */
    public function returnAsset(User $user, Asset $asset): bool
    {
        if (! $user->can('assets.return')) {
            return false;
        }

        return Visibility::assetIsVisible($user, $asset);
    }

    /**
     * Move an asset's lifecycle status — maintenance, damaged, lost,
     * retired — and the only ability that opens a colleague's asset for a
     * write.
     *
     * `assets.manage` rather than `assets.update`, because this is the act
     * that decides what everybody else's list says: an asset in maintenance
     * disappears from the assign form, and a retired one leaves the register
     * permanently. It is also the permission AssetResource checks before
     * printing `purchase_cost`.
     */
    public function changeStatus(User $user, Asset $asset): bool
    {
        if (! $user->can('assets.manage')) {
            return false;
        }

        return Visibility::assetIsVisible($user, $asset);
    }
}
