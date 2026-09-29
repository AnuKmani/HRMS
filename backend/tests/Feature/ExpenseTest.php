<?php

namespace Tests\Feature;

use App\Models\ApprovalRecord;
use App\Models\EmployeeSiteAssignment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenses;
use Tests\TestCase;

/**
 * POST/GET/PUT /api/v1/expenses and its four transitions.
 *
 * An expense is the leave engine pointed at money instead of time, and the
 * file is arranged around the four things that make it different:
 *
 *  - **the claimant is never a field.** `employee_id` is refused with a 422
 *    rather than silently ignored, because "file one on behalf of a
 *    colleague" is not a feature this system has — and a payload that
 *    believed otherwise should be told so rather than left thinking it
 *    worked.
 *  - **the category is data.** The ceiling and the receipt requirement are
 *    read from `expense_categories` on create, on update and again at
 *    submit, so the tests below change a row rather than reaching for a
 *    constant.
 *  - **a claim is booked somewhere.** The site has to belong to the named
 *    project *and* to somewhere this person is actually placed — two checks,
 *    because passing one and failing the other is the normal case for a
 *    client that guessed.
 *  - **the chain is EXP-STD**: reporting manager, then `expenses.manage`
 *    (HR Admin, Payroll Admin, Finance). The second link is a permission
 *    rather than a role, which is what lets a deployment rename its finance
 *    team without editing code.
 *
 * Money is asserted as a *string* throughout. `100.999` becoming `101.00`
 * is the point of DECIMAL(12,2), and a test that cast it to float first
 * would be asserting the bug it is meant to catch.
 */
class ExpenseTest extends TestCase
{
    use BuildsExpenses, RefreshDatabase;

    private Project $project;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedExpenseStack();

        $this->project = Project::factory()->create();
        $this->site = Site::factory()->create(['project_id' => $this->project->id]);
    }

    /* -------------------------------------------------------------- create */

    public function test_a_claim_belongs_to_the_session_and_starts_as_a_draft(): void
    {
        [, $employee] = $this->signInAs('Employee');

        $claim = $this->claim(['currency' => 'aed']);

        $this->assertSame($employee->id, $claim['employee_id']);
        $this->assertSame('draft', $claim['status']);
        $this->assertTrue($claim['is_draft']);
        $this->assertTrue($claim['is_open']);
        $this->assertNull($claim['submitted_at']);
        $this->assertNull($claim['approved_at']);
        $this->assertNull($claim['current_approval_step']);
        $this->assertNull($claim['approval_workflow_id'], 'The chain is frozen at submit, not at create.');
        $this->assertSame('AED', $claim['currency'], 'Normalised on the way in rather than trusted as typed.');
        $this->assertSame('150.00', $claim['amount'], 'Money is a decimal string, never a float.');
        $this->assertSame('2026-09-25', $claim['expense_date']);
        $this->assertSame(0, $claim['receipt_count']);
    }

    public function test_fields_the_client_does_not_own_are_refused_rather_than_ignored(): void
    {
        [, $employee] = $this->signInAs('Employee');

        $this->postJson('/api/v1/expenses', $this->claimPayload([
            'employee_id' => $employee->id + 999,
            'status' => 'approved',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id', 'status']);

        $this->assertSame(
            0,
            Expense::query()->count(),
            'Nothing was written by a payload that lied about who it was for.',
        );
    }

    public function test_the_amount_the_date_and_the_description_are_validated_before_anything_is_written(): void
    {
        $this->signInAs('Employee');

        foreach (['0', '-5.00', 'free', '100000000.00'] as $amount) {
            $this->postJson('/api/v1/expenses', $this->claimPayload(['amount' => $amount]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        // An expense is money already spent. Tomorrow's taxi fare is a plan.
        $this->postJson('/api/v1/expenses', $this->claimPayload(['expense_date' => '2026-09-29']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expense_date');

        $this->postJson('/api/v1/expenses', $this->claimPayload(['expense_date' => 'someday']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expense_date');

        $this->postJson('/api/v1/expenses', $this->claimPayload(['description' => '']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('description');

        $this->postJson('/api/v1/expenses', $this->claimPayload(['currency' => 'AEDIR']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency');

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_the_currency_must_be_one_this_company_accepts(): void
    {
        $this->signInAs('Employee');

        // `system.supported_currencies` is seeded to `["AED"]`. A claim in a
        // code the operator never opted into is refused rather than quietly
        // converted: conversion is FX, and this phase deliberately has none,
        // so a silent one would be a number nobody agreed to.
        $this->postJson('/api/v1/expenses', $this->claimPayload(['currency' => 'JPY']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency');

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_a_claim_keeps_the_currency_it_was_filed_in(): void
    {
        $this->signInAs('Employee');

        // Widen the setting the way an operator would, then file in a second
        // code: the rule reads the row, so it moves when the row moves.
        Setting::query()->where('key', 'system.supported_currencies')
            ->firstOrFail()
            ->update(['value' => '["AED", "USD"]']);

        $claim = $this->claim(['currency' => 'usd']);

        $this->assertSame('USD', $claim['currency']);
        $this->assertSame(
            'USD',
            Expense::query()->findOrFail($claim['id'])->currency,
            'Read back from the row: a payload may echo what it was given.',
        );

        // A correction leaves it alone. Nothing in this API re-prices an
        // existing claim, and the update endpoint offers no way to move one
        // into a currency the configuration never sanctioned.
        $this->putJson('/api/v1/expenses/'.$claim['id'], [
            'description' => 'Corrected description.',
        ])->assertOk();

        $this->assertSame('USD', Expense::query()->findOrFail($claim['id'])->currency);
    }

    public function test_money_is_rounded_to_the_column_and_not_left_to_a_float(): void
    {
        $this->signInAs('Employee');

        $claim = $this->claim(['amount' => '100.999']);

        $this->assertSame('101.00', $claim['amount']);

        $row = Expense::query()->findOrFail($claim['id']);
        $this->assertSame('101.00', (string) $row->amount, 'The column, not the payload, is the record.');
    }

    public function test_the_category_rules_are_read_from_the_table_and_enforced(): void
    {
        $this->signInAs('Employee');

        // FOOD ships with a ceiling of 1000.00 — a configuration value, not
        // a branch anywhere in this file.
        $this->postJson('/api/v1/expenses', $this->claimPayload([
            'expense_category_id' => $this->expenseCategoryId('FOOD'),
            'amount' => '1500.00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        // A retired category cannot be chosen even by a client that still
        // holds its id.
        ExpenseCategory::query()->where('code', 'TRAVEL')->update(['status' => 'inactive']);

        $this->postJson('/api/v1/expenses', $this->claimPayload([
            'expense_category_id' => $this->expenseCategoryId('TRAVEL'),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expense_category_id');

        $this->postJson('/api/v1/expenses', $this->claimPayload(['expense_category_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expense_category_id');

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_the_ceiling_is_reread_at_every_step_so_a_draft_cannot_outrun_it(): void
    {
        $hr = $this->makeSeat('HR Admin');
        $this->signInAs('Employee', ['reporting_manager_id' => $hr[1]->id]);

        $id = $this->claim([
            'expense_category_id' => $this->expenseCategoryId('FOOD'),
            'amount' => '900.00',
        ])['id'];

        // An operator lowers the ceiling after the draft was written. The
        // rule is a row, so the row wins at the next thing this claim tries
        // to do — including submission, which is the last gate.
        ExpenseCategory::query()->where('code', 'FOOD')->update(['maximum_amount' => '100.00']);

        $this->putJson('/api/v1/expenses/'.$id, ['amount' => '900.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->postJson('/api/v1/expenses/'.$id.'/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame('draft', Expense::query()->findOrFail($id)->status);
    }

    /* ---------------------------------------------------- site & project */

    public function test_a_site_must_belong_to_the_project_named_and_to_somewhere_this_person_is_placed(): void
    {
        $otherProject = Project::factory()->create();
        $otherSite = Site::factory()->create(['project_id' => $otherProject->id]);

        $this->signInAs('Employee', [
            'primary_site_id' => $this->site->id,
            'primary_project_id' => $this->project->id,
        ]);

        // The site exists — it just is not in that project. Refused on
        // site_id, because that is the field that is wrong.
        $this->postJson('/api/v1/expenses', $this->claimPayload([
            'project_id' => $this->project->id,
            'site_id' => $otherSite->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');

        // No project named, so there is nothing to check the site against.
        $this->postJson('/api/v1/expenses', $this->claimPayload([
            'site_id' => $this->site->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_id');

        $this->assertSame(0, Expense::query()->count(), 'A half-consistent pair writes nothing at all.');

        $claim = $this->claim([
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]);

        $this->assertSame($this->site->id, $claim['site_id']);
        $this->assertSame($this->project->id, $claim['project_id']);
    }

    public function test_a_claim_may_only_be_booked_against_a_place_this_person_is_actually_at(): void
    {
        // No posting of any kind — somebody else's site.
        $this->signInAs('Employee');

        $this->postJson('/api/v1/expenses', $this->claimPayload([
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');

        // A primary posting is one way in...
        $this->signInAs('Employee', [
            'primary_site_id' => $this->site->id,
            'primary_project_id' => $this->project->id,
        ]);

        $placed = $this->claim([
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]);

        $this->assertNotNull($placed['site_id']);

        // ...an active assignment is another, for somebody with no primary
        // site at all.
        [$user, $posted] = $this->makeSeat('Employee');
        $this->become($user);

        EmployeeSiteAssignment::factory()->create([
            'employee_id' => $posted->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]);

        $postedClaim = $this->claim([
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]);

        $this->assertSame($posted->id, $postedClaim['employee_id']);

        // ...and an assignment that has ended is not.
        [$user, $gone] = $this->makeSeat('Employee');
        $this->become($user);

        EmployeeSiteAssignment::factory()->ended()->create([
            'employee_id' => $gone->id,
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]);

        $this->postJson('/api/v1/expenses', $this->claimPayload([
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');

        // The back office may book anywhere — `expenses.manage` is the
        // fourth and last way in.
        [$user, $hr] = $this->makeSeat('HR Admin');
        $this->become($user);

        $onBehalf = $this->claim([
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]);

        $this->assertSame($hr->id, $onBehalf['employee_id'], 'Her own claim, on a site she never stands on.');
    }

    /* --------------------------------------------------------- transitions */

    public function test_a_draft_can_be_edited_until_it_is_submitted_and_not_after(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $id = $this->claim()['id'];

        $this->putJson('/api/v1/expenses/'.$id, ['amount' => '175.00'])
            ->assertOk()
            ->assertJsonPath('data.amount', '175.00')
            ->assertJsonPath('data.status', 'draft');

        $this->postJson('/api/v1/expenses/'.$id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_approval_step', 1);

        $this->putJson('/api/v1/expenses/'.$id, ['amount' => '200.00'])->assertStatus(409);
        $this->postJson('/api/v1/expenses/'.$id.'/submit')->assertStatus(409);

        $this->assertSame(
            '175.00',
            (string) Expense::query()->findOrFail($id)->amount,
            'The approver is shown what the claimant actually submitted.',
        );
    }

    public function test_the_chain_walks_supervisor_then_finance_and_only_the_current_link_may_sign(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $finance = $this->makeSeat('Finance');
        $hr = $this->makeSeat('HR Admin');

        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $id = $this->claim()['id'];

        $this->postJson('/api/v1/expenses/'.$id.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_approval_step', 1);

        // Finance holds `expenses.manage`, which is what the *second* link
        // resolves to. That is not an invitation to answer the first one —
        // and HR holds it too, for the same reason.
        $this->become($finance[0]);
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertForbidden();

        $this->become($hr[0]);
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertForbidden();

        $this->become($supervisor[0]);
        $this->postJson('/api/v1/expenses/'.$id.'/approve', [
            'remarks' => 'He was on that site that week.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_approval_step', 2);

        $this->become($finance[0]);
        $this->postJson('/api/v1/expenses/'.$id.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.final_approved_by', $finance[0]->id);

        $row = Expense::query()->findOrFail($id);
        $this->assertNotNull($row->approved_at);
        $this->assertNull($row->current_approval_step);
        $this->assertSame($finance[0]->id, $row->final_approved_by);

        // Both links are on the record, in order, with who acted.
        $chain = ApprovalRecord::query()
            ->where('subject_type', ApprovalRecord::TYPE_EXPENSE)
            ->where('subject_id', $id)
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $chain);
        $this->assertSame('approved', $chain[0]->status);
        $this->assertSame($supervisor[0]->id, $chain[0]->acted_by);
        $this->assertSame('He was on that site that week.', $chain[0]->remarks);
        $this->assertSame('approved', $chain[1]->status);
        $this->assertSame($finance[0]->id, $chain[1]->acted_by);
    }

    public function test_a_link_that_has_already_been_answered_cannot_be_answered_twice(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $finance = $this->makeSeat('Finance');

        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $id = $this->claim()['id'];

        $this->postJson('/api/v1/expenses/'.$id.'/submit')->assertOk();

        $this->become($supervisor[0]);
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertOk();

        // The claim moved on. The supervisor is no longer the current link,
        // and "I approved it once" is not a standing right to approve it
        // again.
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertForbidden();
        $this->postJson('/api/v1/expenses/'.$id.'/reject')->assertForbidden();

        $this->become($finance[0]);
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertOk();
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertForbidden();
        $this->postJson('/api/v1/expenses/'.$id.'/reject')->assertForbidden();

        $this->assertSame('approved', Expense::query()->findOrFail($id)->status);

        $decided = ApprovalRecord::query()
            ->where('subject_type', ApprovalRecord::TYPE_EXPENSE)
            ->where('subject_id', $id)
            ->where('status', 'approved')
            ->count();

        $this->assertSame(2, $decided, 'One decision per link, however many times the call was repeated.');
    }

    public function test_nobody_approves_their_own_claim_even_when_the_chain_points_at_them(): void
    {
        [, $employee] = $this->signInAs('Employee');

        // The one configuration that would let this happen: the requester is
        // their own reporting manager, so link one resolves to them.
        $employee->update(['reporting_manager_id' => $employee->id]);

        $id = $this->claim()['id'];
        $this->postJson('/api/v1/expenses/'.$id.'/submit')->assertOk();

        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertForbidden();
        $this->postJson('/api/v1/expenses/'.$id.'/reject')->assertForbidden();

        $row = Expense::query()->findOrFail($id);
        $this->assertSame('pending', $row->status, 'The refusal blocked the act, not the claim.');
        $this->assertNull($row->approved_at);
    }

    public function test_a_supervisor_who_is_not_this_claims_line_manager_cannot_sign(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $stranger = $this->makeSeat('Site Supervisor');

        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $id = $this->claim()['id'];
        $this->postJson('/api/v1/expenses/'.$id.'/submit')->assertOk();

        // Same role, same permission, one step to answer — and not theirs.
        $this->become($stranger[0]);
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertForbidden();

        $this->assertSame('pending', Expense::query()->findOrFail($id)->status);
    }

    public function test_a_refusal_needs_a_reason_closes_the_chain_and_names_who_gave_it(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');

        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $id = $this->claim()['id'];
        $this->postJson('/api/v1/expenses/'.$id.'/submit')->assertOk();

        $this->become($supervisor[0]);

        // A refusal with no reason is a decision nobody can learn from.
        $this->postJson('/api/v1/expenses/'.$id.'/reject')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');

        $this->postJson('/api/v1/expenses/'.$id.'/reject', [
            'remarks' => 'This is covered by the site allowance already paid.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $row = Expense::query()->findOrFail($id);
        $this->assertNotNull($row->rejected_at);
        $this->assertNull($row->current_approval_step);

        $record = ApprovalRecord::query()
            ->where('subject_type', ApprovalRecord::TYPE_EXPENSE)
            ->where('subject_id', $id)
            ->where('status', 'rejected')
            ->first();

        $this->assertNotNull($record);
        $this->assertSame($supervisor[0]->id, $record->acted_by);
        $this->assertSame('This is covered by the site allowance already paid.', $record->remarks);

        // The chain is closed rather than left waiting on a second opinion
        // that can no longer change the outcome.
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertForbidden();
        $this->assertSame(
            0,
            ApprovalRecord::query()
                ->where('subject_type', ApprovalRecord::TYPE_EXPENSE)
                ->where('subject_id', $id)
                ->where('status', 'pending')
                ->count(),
        );
    }

    public function test_a_claim_can_be_cancelled_while_it_is_open_and_never_after(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $finance = $this->makeSeat('Finance');

        [$employeeUser] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $draft = $this->claim()['id'];
        $this->postJson('/api/v1/expenses/'.$draft.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull(Expense::query()->findOrFail($draft)->cancelled_at);
        $this->postJson('/api/v1/expenses/'.$draft.'/cancel')->assertStatus(409);

        // Withdrawing a pending claim closes its chain rather than leaving
        // approvers holding a decision nobody can make.
        $pending = $this->claim()['id'];
        $this->postJson('/api/v1/expenses/'.$pending.'/submit')->assertOk();
        $this->postJson('/api/v1/expenses/'.$pending.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(
            0,
            ApprovalRecord::query()
                ->where('subject_type', ApprovalRecord::TYPE_EXPENSE)
                ->where('subject_id', $pending)
                ->where('status', 'pending')
                ->count(),
        );

        // Money that has been paid out is history, not a draft.
        $settled = $this->claim()['id'];
        $this->postJson('/api/v1/expenses/'.$settled.'/submit')->assertOk();

        $this->become($supervisor[0]);
        $this->postJson('/api/v1/expenses/'.$settled.'/approve')->assertOk();

        $this->become($finance[0]);
        $this->postJson('/api/v1/expenses/'.$settled.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Withdrawal is the claimant's act, so the route gate and the policy
        // both put it back in their hands — and the service still refuses,
        // because money already settled is history rather than a draft.
        $this->become($employeeUser);
        $this->postJson('/api/v1/expenses/'.$settled.'/cancel')->assertStatus(409);
        $this->assertSame('approved', Expense::query()->findOrFail($settled)->status);
    }

    /* --------------------------------------------------------- authorization */

    public function test_an_employee_reads_only_their_own_claims(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');

        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $mine = $this->claim()['id'];

        $colleague = $this->makeSeat('Employee', ['reporting_manager_id' => $supervisor[1]->id]);
        $this->become($colleague[0]);
        $theirs = $this->claim()['id'];

        $items = $this->getJson('/api/v1/expenses')->assertOk()->json('data.items');

        $this->assertSame(
            [$colleague[1]->id],
            array_column($items, 'employee_id'),
            'The permission that reads your own claims does not publish a colleague\'s.',
        );

        $this->getJson('/api/v1/expenses/'.$mine)->assertForbidden();
        $this->getJson('/api/v1/expenses/'.$theirs)->assertOk();
    }

    public function test_the_line_manager_who_must_sign_can_read_what_they_are_being_asked_to_sign(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $stranger = $this->makeSeat('Site Supervisor');

        $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        // No project, no site — a taxi home after a late shift. Nothing in
        // the row points at a place either supervisor supervises, so the
        // reporting-manager link is the *only* thing putting this in front
        // of the person EXP-STD will ask to approve it.
        $id = $this->claim()['id'];

        $this->become($supervisor[0]);

        $this->getJson('/api/v1/expenses/'.$id)->assertOk();

        $items = $this->getJson('/api/v1/expenses')->assertOk()->json('data.items');
        $this->assertSame([$id], array_column($items, 'id'), 'Index and show must agree, or neither is a boundary.');

        // Another line manager, same role and same permissions: not theirs.
        $this->become($stranger[0]);
        $this->getJson('/api/v1/expenses/'.$id)->assertForbidden();
    }

    public function test_roles_without_the_grant_cannot_reach_the_actions_that_need_it(): void
    {
        $management = $this->makeSeat('Management');
        $finance = $this->makeSeat('Finance');
        $employee = $this->makeSeat('Employee');

        // Management is read-only on this module: it may open the list and
        // the category reference, and may file nothing at all.
        $this->become($management[0]);
        $this->getJson('/api/v1/expenses')->assertOk();
        $this->getJson('/api/v1/expense-categories')->assertOk();
        $this->postJson('/api/v1/expenses', $this->claimPayload())->assertForbidden();

        // Finance signs the last link and files nothing of its own — the
        // same read-only stance it takes on leave.
        $this->become($finance[0]);
        $this->postJson('/api/v1/expenses', $this->claimPayload())->assertForbidden();

        // And an employee is never an approver, at any link.
        $supervisor = $this->makeSeat('Site Supervisor');
        $this->become($employee[0]);
        $employee[1]->update(['reporting_manager_id' => $supervisor[1]->id]);

        $id = $this->claim()['id'];
        $this->postJson('/api/v1/expenses/'.$id.'/submit')->assertOk();
        $this->postJson('/api/v1/expenses/'.$id.'/approve')->assertForbidden();
        $this->postJson('/api/v1/expenses/'.$id.'/reject')->assertForbidden();
    }

    /* -------------------------------------------------------------- summary */

    public function test_the_summary_totals_only_what_this_user_may_read_and_honours_the_date_range(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');
        $finance = $this->makeSeat('Finance');

        [$employeeUser] = $this->signInAs('Employee', ['reporting_manager_id' => $supervisor[1]->id]);

        $draft = $this->claim(['amount' => '100.00', 'expense_date' => '2026-09-20']);
        $pending = $this->claim(['amount' => '200.00', 'expense_date' => '2026-09-21']);
        $approved = $this->claim(['amount' => '300.00', 'expense_date' => '2026-09-22']);
        $rejected = $this->claim(['amount' => '400.00', 'expense_date' => '2026-09-23']);
        $cancelled = $this->claim(['amount' => '500.00', 'expense_date' => '2026-09-24']);

        foreach ([$pending, $approved, $rejected, $cancelled] as $claim) {
            $this->postJson('/api/v1/expenses/'.$claim['id'].'/submit')->assertOk();
        }

        $this->become($supervisor[0]);
        $this->postJson('/api/v1/expenses/'.$approved['id'].'/approve')->assertOk();
        $this->postJson('/api/v1/expenses/'.$rejected['id'].'/reject', [
            'remarks' => 'Personal meal, not claimable.',
        ])->assertOk();

        $this->become($finance[0]);
        $this->postJson('/api/v1/expenses/'.$approved['id'].'/approve')->assertOk();

        $this->become($employeeUser);
        $this->postJson('/api/v1/expenses/'.$cancelled['id'].'/cancel')->assertOk();

        $summary = $this->getJson('/api/v1/expenses/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame('100.00', $summary['by_status']['draft']['amount']);
        $this->assertSame('200.00', $summary['by_status']['pending']['amount']);
        $this->assertSame('300.00', $summary['by_status']['approved']['amount']);
        $this->assertSame('400.00', $summary['by_status']['rejected']['amount']);
        $this->assertSame('500.00', $summary['by_status']['cancelled']['amount']);
        $this->assertSame(1, $summary['by_status']['approved']['count']);

        // One category — `OTHER`'s row is named "Other" — five claims, and
        // the total is a string that adds up.
        $this->assertCount(1, $summary['by_category']);
        $this->assertSame('Other', $summary['by_category'][0]['name']);
        $this->assertSame(5, $summary['by_category'][0]['count']);
        $this->assertSame('1500.00', $summary['by_category'][0]['amount']);

        // A range that covers two of the five — containment, not equality,
        // and the two claims keep the status each one actually ended in.
        $window = $this->getJson('/api/v1/expenses/summary?from=2026-09-22&to=2026-09-23')
            ->assertOk()
            ->json('data');

        $this->assertSame('300.00', $window['by_status']['approved']['amount']);
        $this->assertSame('400.00', $window['by_status']['rejected']['amount']);
        $this->assertSame('0.00', $window['by_status']['pending']['amount']);
        $this->assertSame('0.00', $window['by_status']['draft']['amount']);

        // A colleague's summary is their own, and they have nothing yet.
        $this->become($this->makeSeat('Employee')[0]);

        $theirs = $this->getJson('/api/v1/expenses/summary')->assertOk()->json('data');
        $this->assertSame('0.00', $theirs['by_status']['approved']['amount']);
        $this->assertSame([], $theirs['by_category']);
        $this->assertSame([], $theirs['by_project']);
    }

    public function test_the_list_filters_by_status_employee_project_and_date(): void
    {
        $supervisor = $this->makeSeat('Site Supervisor');

        $this->signInAs('Employee', [
            'reporting_manager_id' => $supervisor[1]->id,
            'primary_site_id' => $this->site->id,
            'primary_project_id' => $this->project->id,
        ]);

        $first = $this->claim(['amount' => '100.00', 'expense_date' => '2026-09-20']);
        $second = $this->claim([
            'amount' => '200.00',
            'expense_date' => '2026-09-24',
            'project_id' => $this->project->id,
            'site_id' => $this->site->id,
        ]);

        $this->postJson('/api/v1/expenses/'.$second['id'].'/submit')->assertOk();

        $pending = $this->getJson('/api/v1/expenses?status=pending')->assertOk()->json('data.items');
        $this->assertSame([$second['id']], array_column($pending, 'id'));

        $byProject = $this->getJson('/api/v1/expenses?project_id='.$this->project->id)
            ->assertOk()
            ->json('data.items');
        $this->assertSame([$second['id']], array_column($byProject, 'id'));

        $bySite = $this->getJson('/api/v1/expenses?site_id='.$this->site->id)
            ->assertOk()
            ->json('data.items');
        $this->assertSame([$second['id']], array_column($bySite, 'id'));

        $byDate = $this->getJson('/api/v1/expenses?from=2026-09-21&to=2026-09-30')
            ->assertOk()
            ->json('data.items');
        $this->assertSame([$second['id']], array_column($byDate, 'id'));

        $byCategory = $this->getJson('/api/v1/expenses?category='.$first['expense_category_id'])
            ->assertOk()
            ->json('data.items');
        $this->assertCount(2, $byCategory, 'Both are OTHER; the filter narrows on other dimensions.');

        $byEmployee = $this->getJson('/api/v1/expenses?employee_id='.$supervisor[1]->id)
            ->assertOk()
            ->json('data.items');
        $this->assertSame([], $byEmployee, 'Filtering for somebody outside your scope returns nothing, not everything.');
    }
}
