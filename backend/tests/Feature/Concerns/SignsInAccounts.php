<?php

namespace Tests\Feature\Concerns;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

/**
 * Becoming somebody.
 *
 * Split out of `BuildsLeaveStack` because it is not leave-specific — it is
 * the same three moves every multi-role feature test makes — and a Phase 7
 * test should not have to seed leave types to be allowed to sign in as a
 * site supervisor. Behaviour is unchanged; the methods simply live here now
 * and `BuildsLeaveStack` re-exports them by using this trait.
 *
 * Building the seat and *becoming* it are separate on purpose: several
 * tests need a supervisor, a project manager and a requester to exist
 * before deciding which one the next request speaks as.
 */
trait SignsInAccounts
{
    /**
     * An account with its own employee row — created, but NOT signed in.
     *
     * @param  array<string, mixed>  $employee
     * @return array{0: User, 1: Employee}
     */
    protected function makeSeat(string $role, array $employee = []): array
    {
        $model = Employee::factory()->create($employee);

        $user = User::factory()->create();
        $user->assignRole($role);
        $model->update(['user_id' => $user->id]);

        return [$user, $model];
    }

    /**
     * Create an account and become it.
     *
     * @param  array<string, mixed>  $employee
     * @return array{0: User, 1: Employee}
     */
    protected function signInAs(string $role, array $employee = []): array
    {
        [$user, $model] = $this->makeSeat($role, $employee);

        $this->become($user);

        return [$user, $model];
    }

    /**
     * Swap the caller.
     *
     * Deliberately not called `actingAs()`: Laravel's own
     * `InteractsWithAuthentication::actingAs()` already owns that name on
     * TestCase, and a trait method cannot lower its visibility.
     *
     * Guards are forgotten first for the reason TestCase::withToken() gives:
     * the same application instance serves every request in a test, so the
     * memoised Sanctum guard would keep authenticating the first caller for
     * the rest of the file.
     */
    protected function become(User $user): void
    {
        Auth::forgetGuards();

        Sanctum::actingAs($user);
    }
}
