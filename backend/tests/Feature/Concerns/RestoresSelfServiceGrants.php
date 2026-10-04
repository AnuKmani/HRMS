<?php

namespace Tests\Feature\Concerns;

use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives the Employee role back the self-service grants the business has
 * since withdrawn from it, for the tests that exercise those features.
 *
 * The features themselves — filing an expense, uploading your own
 * document, claiming overtime by hand, handing over a training card,
 * reading your own payroll row — still exist and are still enforced for
 * whoever holds the grant. What changed is only *who* the seeded Employee
 * role is (see RolePermissionSeeder). These suites test the features, so
 * they put the grants back on the role they were written against rather
 * than inventing a second role that would drift from it.
 */
trait RestoresSelfServiceGrants
{
    /** @var array<int, string> */
    private const SELF_SERVICE_GRANTS = [
        'overtime.create',
        'documents.view', 'documents.create', 'documents.update',
        'onboarding.view',
        'training.view',
        'expenses.view', 'expenses.create', 'expenses.update',
        'payroll.view',
    ];

    protected function restoreSelfServiceGrants(): void
    {
        Role::findByName('Employee', 'web')->givePermissionTo(self::SELF_SERVICE_GRANTS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
