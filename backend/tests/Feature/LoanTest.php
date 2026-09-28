<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Payroll;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsLeaveStack;
use Tests\TestCase;

/**
 * POST/PUT /api/v1/loans and its four transitions.
 *
 * A loan is the one place this application lets money flow *out* of payroll
 * on the strength of a decision a person made about another person, so the
 * file is arranged around the three rules that keep it honest:
 *
 *  - **nobody signs off on their own debt.** Tested through a role that
 *    *holds* `loans.approve`, because a refusal produced by the route's
 *    coarse gate proves only that the route exists.
 *
 *  - **the schedule is derived once, at approval.** n installments sum to
 *    the principal exactly, and the last one carries the remainder - a
 *    schedule that cannot close is a loan that can never complete.
 *
 *  - **an installment is taken once, or given back.** Payroll claims it,
 *    a re-run releases it before claiming again, and neither path can
 *    decrement the balance twice.
 */
class LoanTest extends TestCase
{
    use BuildsLeaveStack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLeaveStack();
    }

    /* ------------------------------------------------------------- create */

    public function test_a_loan_starts_as_a_draft_with_no_schedule_and_no_balance_moved(): void
    {
        [, $employee] = $this->signInAs('Employee', ['salary' => 30000]);

        $loan = $this->ask(12000, 12, '2026-09-30');

        $this->assertSame($employee->id, $loan['employee_id']);
        $this->assertSame('draft', $loan['status']);
        $this->assertSame(12000.0, (float) $loan['principal_amount']);
        $this->assertSame(1000.0, (float) $loan['installment_amount']);
        $this->assertSame(12000.0, (float) $loan['outstanding_balance']);

        // Nothing to repay yet: the schedule is minted by the approval, so
        // a draft cannot already be showing a repayment plan.
        $this->assertSame(0, LoanInstallment::query()->count());

        // And every figure the client is not allowed to state is ignored
        // rather than accepted - status, balance, approver, schedule.
        $refused = $this->postJson('/api/v1/loans', [
            'employee_id' => $employee->id + 999,
            'principal_amount' => 500,
            'number_of_installments' => 1,
            'start_date' => '2026-09-30',
            'status' => 'active',
            'outstanding_balance' => 0,
            'approved_by' => $employee->id,
        ])->assertUnprocessable();

        $refused->assertJsonValidationErrors('employee_id');
        $this->assertSame(1, Loan::query()->count());
    }

    /**
     * "Borrowing for myself" needs no knowledge of my own row id.
     *
     * The same default the certificate endpoint already makes, and the reason
     * is the same: a person asking for an advance from a phone is not looking
     * their employee number up first. An account with *no* employee record is
     * still refused, by the `required` rule rather than by a foreign key
     * violation further down.
     */
    public function test_nobody_has_to_know_their_own_employee_id_to_borrow(): void
    {
        [, $employee] = $this->signInAs('Employee', ['salary' => 30000]);

        $loan = $this->postJson('/api/v1/loans', [
            'principal_amount' => 6000,
            'number_of_installments' => 6,
            'start_date' => '2026-10-05',
        ])->assertCreated()->json('data');

        $this->assertSame($employee->id, $loan['employee_id']);
        $this->assertSame('draft', $loan['status']);
    }

    public function test_a_loan_may_only_be_asked_for_by_someone_who_has_a_salary_to_repay(): void
    {
        $this->signInAs('Employee', ['salary' => 30000]);

        $this->postJson('/api/v1/loans', [
            'principal_amount' => 0,
            'number_of_installments' => 0,
            'start_date' => 'tomorrow-ish',
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'principal_amount',
            'number_of_installments',
            'start_date',
        ]);

        // A schedule whose full payments finish the principal before the
        // last one would mint a zero or negative final row - a loan that
        // can never reach `completed`, because `completed` is decided by
        // the balance hitting zero.
        $this->postJson('/api/v1/loans', [
            'principal_amount' => 1000,
            'installment_amount' => 600,
            'number_of_installments' => 3,
            'start_date' => '2026-09-30',
        ])->assertUnprocessable()->assertJsonValidationErrors('installment_amount');
    }

    /* --------------------------------------------------------- transitions */

    public function test_nobody_approves_their_own_loan_even_when_they_hold_the_grant(): void
    {
        // HR Admin holds `loans.approve`, so the route's coarse gate opens
        // and the refusal has to come from the rule itself.
        [, $employee] = $this->signInAs('HR Admin', ['salary' => 30000]);

        $id = $this->ask(6000, 6, '2026-10-05')['id'];
        $this->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        $this->postJson("/api/v1/loans/{$id}/approve")->assertForbidden();
        $this->postJson("/api/v1/loans/{$id}/reject")->assertForbidden();

        $row = Loan::query()->findOrFail($id);
        $this->assertSame(Loan::STATUS_PENDING, $row->status, 'The refusal blocked the act, not the request.');
        $this->assertNull($row->approved_at);
        $this->assertSame(0, LoanInstallment::query()->count());
    }

    public function test_an_approval_mints_a_schedule_that_closes_the_principal_exactly(): void
    {
        $this->signInAs('Employee', ['salary' => 30000]);
        $id = $this->ask(100, 3, '2026-10-05', ['installment_amount' => 33])['id'];

        $this->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        $approver = $this->makeSeat('Payroll Admin')[0];
        $this->become($approver);

        // A start date that has not arrived: approved but not yet open,
        // because nothing could possibly repay it yet.
        $this->postJson("/api/v1/loans/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $loan = Loan::query()->findOrFail($id);
        $installments = $loan->installments()->orderBy('sequence')->get();

        $this->assertCount(3, $installments);
        $this->assertSame([33.0, 33.0, 34.0], $installments->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(
            100.0,
            Money::sum($installments->pluck('amount')->all()),
            'The last installment carries the remainder, so the schedule sums to the principal.',
        );
        $this->assertSame(
            ['2026-10-05', '2026-11-05', '2026-12-05'],
            $installments->pluck('due_date')->map(fn ($d) => $d->toDateString())->all(),
        );
        $this->assertSame(100.0, (float) $loan->outstanding_balance);
        $this->assertNotNull($loan->approved_at);
        $this->assertSame($approver->id, $loan->approved_by);
    }

    public function test_a_refusal_leaves_nothing_to_repay(): void
    {
        $this->signInAs('Employee', ['salary' => 30000]);
        $id = $this->ask(6000, 6, '2026-10-05')['id'];
        $this->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        $this->become($this->makeSeat('Payroll Admin')[0]);
        $this->postJson("/api/v1/loans/{$id}/reject", ['remarks' => 'Try again next quarter.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $row = Loan::query()->findOrFail($id);
        $this->assertNotNull($row->rejected_at);
        $this->assertSame('Try again next quarter.', $row->remarks);
        $this->assertSame(0, LoanInstallment::query()->count());
        $this->assertSame(6000.0, (float) $row->outstanding_balance, 'A refusal changed no money.');

        // The decision is final - a second answer to the same question is
        // not an answer, it is an overwrite.
        $this->postJson("/api/v1/loans/{$id}/approve")->assertForbidden();
        $this->postJson("/api/v1/loans/{$id}/reject")->assertForbidden();
    }

    public function test_a_draft_can_be_edited_and_a_submitted_one_cannot(): void
    {
        [, $employee] = $this->signInAs('Employee', ['salary' => 30000]);

        $id = $this->ask(6000, 6, '2026-10-05')['id'];

        $this->putJson("/api/v1/loans/{$id}", ['principal_amount' => 9000])
            ->assertOk()
            ->assertJsonPath('data.principal_amount', '9000.00');

        // Moving the principal without a new installment amount re-divides
        // - and never silently rewrites an installment amount when only the
        // remarks changed.
        $this->assertSame(1500.0, (float) Loan::query()->findOrFail($id)->installment_amount);

        $this->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        $this->putJson("/api/v1/loans/{$id}", ['principal_amount' => 12000])->assertStatus(409);
        $this->postJson("/api/v1/loans/{$id}/submit")->assertStatus(409);

        $this->assertSame(9000.0, (float) Loan::query()->findOrFail($id)->principal_amount);
    }

    public function test_a_loan_can_be_withdrawn_until_a_decision_is_made_and_not_after(): void
    {
        [$borrower] = $this->signInAs('Employee', ['salary' => 30000]);

        $draft = $this->ask(6000, 6, '2026-10-05')['id'];
        $this->postJson("/api/v1/loans/{$draft}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/loans/{$draft}/cancel")->assertStatus(409);

        $pending = $this->ask(6000, 6, '2026-10-05')['id'];
        $this->postJson("/api/v1/loans/{$pending}/submit")->assertOk();
        $this->postJson("/api/v1/loans/{$pending}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Once somebody has said yes the loan is a record of that decision.
        // The borrower may still *ask* to cancel - the policy says their
        // own rows are theirs - but the service refuses the state, and the
        // refusal names the state rather than pretending it was a
        // permission problem. So does the approver's, who may manage loans
        // and still cannot undo one that is under way.
        $active = $this->ask(6000, 6, '2026-10-05')['id'];
        $this->postJson("/api/v1/loans/{$active}/submit")->assertOk();
        $this->become($this->makeSeat('Payroll Admin')[0]);
        $this->postJson("/api/v1/loans/{$active}/approve")->assertOk();

        $this->postJson("/api/v1/loans/{$active}/cancel")->assertStatus(409);

        $this->become($borrower);
        $this->postJson("/api/v1/loans/{$active}/cancel")->assertStatus(409);
    }

    /* --------------------------------------------------------- visibility */

    public function test_a_loan_is_readable_only_by_its_borrower_or_by_someone_who_may_manage_them(): void
    {
        [$mineUser, $mine] = $this->signInAs('Employee', ['salary' => 30000]);
        $myId = $this->ask(6000, 6, '2026-10-05')['id'];

        // Written from an HR desk, because `loans.create` on its own means
        // "I may borrow" and never "I may borrow for somebody else".
        [, $theirs] = $this->makeSeat('Employee', ['salary' => 30000]);
        $this->become($this->makeSeat('HR Admin')[0]);
        $theirsId = $this->askFor($theirs, 6000, 6, '2026-10-05');

        $this->become($mineUser);

        // Nothing in `mayViewOthersLoans` falls back to `employees.view`,
        // so a colleague's debt is not published by the permission that
        // reads your own.
        $items = $this->getJson('/api/v1/loans')->assertOk()->json('data.items');
        $this->assertSame([$mine->id], array_column($items, 'employee_id'));

        $this->getJson("/api/v1/loans/{$theirsId}")->assertForbidden();
        $this->getJson("/api/v1/loans/{$myId}")->assertOk();

        // Somebody who may manage loans reads every one of them.
        $this->become($this->makeSeat('HR Admin')[0]);
        $this->getJson("/api/v1/loans/{$theirsId}")->assertOk();
    }

    public function test_a_role_without_the_approve_grant_cannot_reach_the_decision(): void
    {
        $this->signInAs('Employee', ['salary' => 30000]);
        $id = $this->ask(6000, 6, '2026-10-05')['id'];
        $this->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        // HR Executive holds `loans.view` and `loans.create` - enough to
        // ask and read, never to answer - and the route says so before any
        // policy is consulted.
        $this->signInAs('HR Executive', ['salary' => 30000]);
        $this->postJson("/api/v1/loans/{$id}/approve")->assertForbidden();
        $this->postJson("/api/v1/loans/{$id}/reject")->assertForbidden();

        $this->assertSame(Loan::STATUS_PENDING, Loan::query()->findOrFail($id)->status);
    }

    /* ------------------------------------------------------------ payroll */

    public function test_a_repayment_is_taken_by_a_run_and_given_back_before_a_recalculation(): void
    {
        [, $employee] = $this->signInAs('Employee', ['salary' => 30000]);
        $id = $this->ask(12000, 12, '2026-09-30')['id'];
        $this->postJson("/api/v1/loans/{$id}/submit")->assertOk();

        $this->become($this->makeSeat('Payroll Admin', ['salary' => 50000])[0]);
        $this->postJson("/api/v1/loans/{$id}/approve")->assertOk();

        // The start date has arrived by the time the run reaches the end of
        // September, so the run opens the loan and takes the first
        // installment in one transaction - no second clock.
        $report = $this->postJson('/api/v1/payroll/process', ['year' => 2026, 'month' => 9])
            ->assertOk()
            ->json('data');
        $this->assertSame(0, $report['draft']);

        $loan = Loan::query()->findOrFail($id);
        $this->assertSame(Loan::STATUS_ACTIVE, $loan->status);
        $this->assertSame(11000.0, (float) $loan->outstanding_balance);

        $taken = $loan->installments()->where('status', LoanInstallment::STATUS_DEDUCTED)->get();
        $this->assertCount(1, $taken);
        $this->assertNotNull($taken->first()->payroll_id);

        $row = Payroll::query()
            ->where('employee_id', $employee->id)
            ->where('payroll_year', 2026)
            ->where('payroll_month', 9)
            ->firstOrFail();
        $this->assertSame(1000.0, (float) $row->loan_deduction);

        // Running the month again - the row is still `calculated`, so it is
        // legitimately re-runnable - must not take a second installment or
        // decrement the balance twice.
        $this->postJson('/api/v1/payroll/process', ['year' => 2026, 'month' => 9])->assertOk();

        $this->assertSame(1, $loan->installments()->where('status', LoanInstallment::STATUS_DEDUCTED)->count());
        $this->assertSame(11000.0, (float) Loan::query()->findOrFail($id)->outstanding_balance);
        $this->assertSame(1000.0, (float) $row->fresh()->loan_deduction);

        // And giving it back on purpose: a recalculation releases before it
        // re-claims, so the figure is still 1,000.00 rather than zero.
        $this->postJson("/api/v1/payroll/{$row->id}/recalculate")->assertOk();

        $this->assertSame(1000.0, (float) $row->fresh()->loan_deduction);
        $this->assertSame(11000.0, (float) Loan::query()->findOrFail($id)->outstanding_balance);
        $this->assertSame(1, $loan->installments()->where('status', LoanInstallment::STATUS_DEDUCTED)->count());
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Ask for a loan as whoever is signed in - which is the only account
     * allowed to, unless it also holds `loans.manage`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function ask(float $principal, int $count, string $start, array $overrides = []): array
    {
        $employee = auth()->user()?->employee;

        $this->assertNotNull($employee, 'The signed-in account needs an employee row to borrow against.');

        return $this->postJson('/api/v1/loans', array_merge([
            'employee_id' => $employee->id,
            'principal_amount' => $principal,
            'number_of_installments' => $count,
            'start_date' => $start,
            'reference' => 'LN-'.str_pad((string) (Loan::query()->count() + 1), 4, '0', STR_PAD_LEFT),
        ], $overrides))->assertCreated()->json('data');
    }

    /**
     * Ask on behalf of a specific employee - the shape an HR desk uses,
     * and refused for anybody without `loans.manage`.
     */
    private function askFor(Employee $employee, float $principal, int $count, string $start): int
    {
        return $this->postJson('/api/v1/loans', [
            'employee_id' => $employee->id,
            'principal_amount' => $principal,
            'number_of_installments' => $count,
            'start_date' => $start,
        ])->assertCreated()->json('data.id');
    }
}
