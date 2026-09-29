<?php

namespace Tests\Feature\Concerns;

use App\Models\ExpenseCategory;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Carbon;

/**
 * The Phase 9 furniture every expense test needs, so each test file can
 * spend its lines on the rule it is proving rather than on seeding.
 *
 * Six seeders, always in this order — permissions need roles, EXP-STD needs
 * the permissions it names to exist before a chain can resolve them, and the
 * categories are read by ExpenseService at create time, so a test that
 * skipped them would fail at the first claim rather than at the assertion.
 *
 * The clock is pinned to Monday 2026-09-28, which gives "today" one answer:
 * `before_or_equal:today` on the expense date means nothing different
 * depending on which day the suite happens to run on.
 *
 * Signing in is {@see SignsInAccounts}, which this trait pulls in so every
 * expense test that reaches for `signInAs()` keeps finding it.
 */
trait BuildsExpenses
{
    use SignsInAccounts;

    /**
     * The fixed Monday the Phase 9 tests reason from.
     */
    private const ANCHOR = '2026-09-28';

    protected function seedExpenseStack(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(ApprovalWorkflowSeeder::class);
        $this->seed(ExpenseCategorySeeder::class);

        $this->travelTo(Carbon::parse(self::ANCHOR.' 09:00:00'));
    }

    protected function expenseCategoryId(string $code): int
    {
        return (int) ExpenseCategory::query()->where('code', $code)->value('id');
    }

    /**
     * The body of a valid claim, for a test that wants to break exactly one
     * field of it.
     *
     * Split out from {@see self::claim()} because the interesting tests are
     * the ones that *fail* validation, and asserting `assertCreated()` inside
     * the helper would make those impossible to express.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function claimPayload(array $overrides = []): array
    {
        return array_merge([
            'expense_date' => '2026-09-25',
            'expense_category_id' => $this->expenseCategoryId('OTHER'),
            'amount' => '150.00',
            'currency' => 'AED',
            'description' => 'Site consumables bought on credit.',
        ], $overrides);
    }

    /**
     * Create a draft claim as whoever is currently signed in.
     *
     * Defaults to `OTHER` — no receipt required, no ceiling — so the helper
     * does not silently decide for a test whether it meant to attach
     * evidence. A test that needs either overrides the category.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed> the `data` half of the created envelope
     */
    protected function claim(array $overrides = []): array
    {
        return $this->postJson('/api/v1/expenses', $this->claimPayload($overrides))
            ->assertCreated()
            ->json('data');
    }

    /**
     * A draft, submitted — the state every approval test starts from.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function submittedClaim(array $overrides = []): array
    {
        $claim = $this->claim($overrides);

        $this->postJson('/api/v1/expenses/'.$claim['id'].'/submit')->assertOk();

        return $claim;
    }
}
