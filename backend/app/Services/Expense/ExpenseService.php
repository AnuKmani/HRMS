<?php

namespace App\Services\Expense;

use App\Events\ExpenseDecided;
use App\Models\ApprovalRecord;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Services\Approval\ApprovalWorkflowService;
use App\Support\Money;
use App\Support\Visibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to `expenses` and `expense_receipts`, in one place.
 *
 * The constraint is the one LeaveRequestService and SiteActivityReportService
 * work under, for the same reason: these rows are an employee's word being
 * turned into a payout, so the sequence — does the category allow this, does
 * the person have any business claiming against that site, is there evidence,
 * who signs off, when is it settled — has to happen in one order with one
 * implementation. A controller that assembled the row itself would eventually
 * grow a second caller that got one of those steps wrong, and "wrong" here
 * means paying for something that was never spent.
 *
 * Two vocabularies, and this class is the only one allowed to change the
 * first of them:
 *
 *   - **status** is the lifecycle — draft -> pending -> approved, with
 *     rejected and cancelled as the two ways out — and no endpoint anywhere
 *     writes it. The five transitions below are the only paths in.
 *   - **the approval chain** belongs to ApprovalWorkflowService. This class
 *     decides *when* a chain starts and *when* the claim is settled by its
 *     outcome; it never decides who approves, because that is data in
 *     `approval_workflows` and not a fact about expenses.
 *
 * Every transition also routes through that engine deliberately: full audit
 * logging is still deferred, but a transition that can only happen here is a
 * transition an audit log can be attached to later without hunting for a
 * second path that missed it.
 */
final class ExpenseService
{
    /** A ceiling on evidence, not a business rule: 10 scans is a fat claim. */
    private const MAX_RECEIPTS = 10;

    public function __construct(
        private readonly ApprovalWorkflowService $approvals,
        private readonly ExpenseReceiptStore $files,
    ) {}

    /* ------------------------------------------------------------- writes */

    public function create(User $user, array $data): Expense
    {
        $employee = $this->employeeFor($user);
        $category = $this->categoryFor($data);

        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $siteId = isset($data['site_id']) ? (int) $data['site_id'] : null;
        $amount = Money::round($data['amount']);

        return DB::transaction(function () use ($user, $employee, $category, $data, $projectId, $siteId, $amount) {
            $this->assertClaimable($user, $projectId, $siteId, $category, $amount);

            // `employee_id` comes from the session and nowhere else. A
            // payload that names a colleague is not "claiming on behalf of
            // somebody" — it is claiming on their behalf, and this system
            // has no such feature.
            return Expense::query()->create([
                'employee_id' => $employee->id,
                'expense_category_id' => $category->id,
                'project_id' => $projectId,
                'site_id' => $siteId,
                'expense_date' => $data['expense_date'],
                'amount' => $amount,
                'currency' => strtoupper((string) $data['currency']),
                'description' => trim((string) $data['description']),
                'status' => Expense::STATUS_DRAFT,
            ]);
        });
    }

    /**
     * Correct a draft. State is checked first so a second edit attempt after
     * submission reads "already submitted" rather than "you cannot edit
     * this", and the category's rules are re-read rather than trusted from
     * the first draft — the category is a row an operator can change.
     */
    public function update(User $user, Expense $expense, array $data): Expense
    {
        if (! $expense->isDraft()) {
            abort(409, 'Only a draft claim can be edited. Cancel it and file a new one.');
        }

        // A category that was not named keeps the one already on the row —
        // an update says what changed, and re-reading the rule from the
        // *stored* category is what makes a partial edit safe.
        $category = $this->categoryFor([
            'expense_category_id' => $data['expense_category_id'] ?? $expense->expense_category_id,
        ]);

        $projectId = array_key_exists('project_id', $data)
            ? ($data['project_id'] === null ? null : (int) $data['project_id'])
            : $expense->project_id;

        $siteId = array_key_exists('site_id', $data)
            ? ($data['site_id'] === null ? null : (int) $data['site_id'])
            : $expense->site_id;

        $amount = array_key_exists('amount', $data) ? Money::round($data['amount']) : (float) $expense->amount;

        return DB::transaction(function () use ($user, $expense, $data, $category, $projectId, $siteId, $amount) {
            $this->assertClaimable($user, $projectId, $siteId, $category, $amount);

            $expense->fill([
                'expense_category_id' => $category->id,
                'project_id' => $projectId,
                'site_id' => $siteId,
                'expense_date' => $data['expense_date'] ?? $expense->expense_date,
                'amount' => $amount,
                'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : $expense->currency,
                'description' => isset($data['description']) ? trim((string) $data['description']) : $expense->description,
            ])->save();

            return $expense;
        });
    }

    /**
     * Send a draft down the chain.
     *
     * Everything is re-checked here rather than at create: the holiday
     * calendar has nothing to do with it, but the category table, the
     * assignment table and the receipt folder are all editable by other
     * people between the draft and the moment somebody hits send, and this
     * is the last moment before the claim starts costing money.
     */
    public function submit(User $user, Expense $expense): Expense
    {
        if (! $expense->isDraft()) {
            abort(409, 'Only a draft claim can be submitted.');
        }

        $category = $this->categoryFor(['expense_category_id' => $expense->expense_category_id]);

        return DB::transaction(function () use ($user, $expense, $category) {
            $this->assertClaimable(
                $user,
                $expense->project_id !== null ? (int) $expense->project_id : null,
                $expense->site_id !== null ? (int) $expense->site_id : null,
                $category,
                (float) $expense->amount,
            );

            $this->assertReceiptRequirement($expense, $category);

            $expense->status = Expense::STATUS_PENDING;
            $expense->submitted_at = now();
            $expense->save();

            // The chain is copied onto the claim here and never rewritten
            // after this line: editing EXP-STD next month must not re-route
            // a claim already sitting in a supervisor's queue.
            $outcome = $this->approvals->start($expense);

            if ($outcome === ApprovalWorkflowService::COMPLETED) {
                // Every step resolved to nobody. Rather than leaving the
                // claim pending forever on a chain that can never be
                // answered, it settles here — the same answer leave gives.
                $this->finaliseApproval($expense, null);
            }

            return $expense->refresh();
        });
    }

    /**
     * Sign the current step off. Settlement happens only when the *last*
     * step is decided; an intermediate approval leaves the claim pending and
     * moves `current_approval_step` on.
     */
    public function approve(User $user, Expense $expense, string $remarks = ''): Expense
    {
        if (! $expense->isPending()) {
            abort(409, 'This claim is not waiting for approval.');
        }

        return DB::transaction(function () use ($user, $expense, $remarks) {
            $outcome = $this->approvals->approve($expense, $user, $remarks);

            if ($outcome === ApprovalWorkflowService::COMPLETED) {
                $this->finaliseApproval($expense, $user);
            } else {
                // Not a no-op: the engine moved `current_approval_step` and
                // the record rows, so the subject has to be written too.
                $expense->save();
            }

            return $expense->refresh();
        });
    }

    public function reject(User $user, Expense $expense, string $remarks = ''): Expense
    {
        if (! $expense->isPending()) {
            abort(409, 'This claim is not waiting for approval.');
        }

        return DB::transaction(function () use ($user, $expense, $remarks) {
            // The refusal and its remark land on the *current* step of the
            // materialised chain — that is where "who said no, and why" is
            // answerable from — and the remaining steps are closed behind it.
            $this->approvals->reject($expense, $user, $remarks);

            $expense->status = Expense::STATUS_REJECTED;
            $expense->rejected_at = now();
            $expense->current_approval_step = null;
            $expense->save();

            event(new ExpenseDecided($expense, ExpenseDecided::REJECTED, $user));

            return $expense->refresh();
        });
    }

    public function cancel(User $user, Expense $expense): Expense
    {
        if (! $expense->isOpen()) {
            abort(409, 'Only a draft or a pending claim can be cancelled.');
        }

        $wasPending = $expense->isPending();

        return DB::transaction(function () use ($expense, $wasPending) {
            if ($wasPending) {
                // Withdrawn, not rejected: the open steps are closed without
                // an approver because cancelling is not an approval act.
                $this->approvals->abandon($expense);
            }

            $expense->status = Expense::STATUS_CANCELLED;
            $expense->cancelled_at = now();
            $expense->current_approval_step = null;
            $expense->save();

            return $expense->refresh();
        });
    }

    /* ------------------------------------------------------------ receipts */

    /**
     * Attach receipts to a draft — a batch at a time, because a claim saved
     * on one bar of signal should not have to re-upload its evidence on
     * every correction.
     */
    public function addReceipts(User $user, Expense $expense, array $files): Expense
    {
        if (! $expense->isDraft()) {
            abort(409, 'Receipts can only be changed while the claim is a draft.');
        }

        $existing = $expense->receipts()->count();

        if ($existing + count($files) > self::MAX_RECEIPTS) {
            abort(422, 'A claim can carry at most '.self::MAX_RECEIPTS.' receipts.');
        }

        return DB::transaction(function () use ($user, $expense, $files) {
            foreach ($files as $file) {
                $expense->receipts()->create([
                    'path' => $this->files->store($file, $expense),
                    'original_name' => $this->originalName($file),
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'uploaded_by' => $user->id,
                ]);
            }

            return $expense->load('receipts');
        });
    }

    public function removeReceipt(User $user, Expense $expense, ExpenseReceipt $receipt): void
    {
        // Route-model-bounded by the URL, but a receipt row whose expense_id
        // disagrees with the parent is exactly the sort of thing a race or a
        // hand-built URL produces, so it is answered here too.
        if ((int) $receipt->expense_id !== (int) $expense->id) {
            abort(404, 'That receipt is not attached to this claim.');
        }

        if (! $expense->isDraft()) {
            abort(409, 'Receipts can only be changed while the claim is a draft.');
        }

        DB::transaction(function () use ($receipt) {
            $this->files->delete($receipt->path);
            $receipt->delete();
        });
    }

    /* ----------------------------------------------------------- read help */

    /**
     * The materialised chain, for the timeline a detail screen draws.
     *
     * @return array<int, ApprovalRecord>
     */
    public function timeline(Expense $expense): array
    {
        return $this->approvals->history($expense)->all();
    }

    /* ------------------------------------------------------------ internals */

    /**
     * The three rules that make a claim claimable at all, asked on create,
     * on update and again at submit:
     *
     *  - the category exists *and is active* — an operator who retires
     *    "Food" should not have claims nobody can create still accepted by
     *    a client that cached the id;
     *  - the amount is inside the category's ceiling, if it has one;
     *  - this person may book against this project/site (see
     *    Visibility::mayClaimExpenseAt() for the four ways in).
     *
     * Each is a ValidationException rather than an abort(422) so the error
     * arrives keyed to the field the form is drawing — "That site does not
     * belong to the selected project" belongs under site_id, not in a banner.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertClaimable(
        User $user,
        ?int $projectId,
        ?int $siteId,
        ExpenseCategory $category,
        float $amount,
    ): void {
        if (! $category->accepts($amount)) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    '%s claims are capped at %s. Enter a smaller amount or choose a different category.',
                    $category->name,
                    (string) $category->maximum_amount,
                ),
            ]);
        }

        if (! Visibility::mayClaimExpenseAt($user, $projectId, $siteId)) {
            throw ValidationException::withMessages([
                $siteId !== null ? 'site_id' : 'project_id' => 'You may only claim against a project or site you are assigned to.',
            ]);
        }
    }

    /**
     * A category that demands evidence does not get a claim without it.
     *
     * Asked at submit rather than at create, deliberately: the whole point
     * of a draft is that the receipt folder fills up after the claim is
     * written, and a rule that fired on create would make the draft
     * unwritable before its evidence could be attached.
     */
    private function assertReceiptRequirement(Expense $expense, ExpenseCategory $category): void
    {
        if (! $category->requires_receipt) {
            return;
        }

        if ($expense->receipts()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'receipts' => sprintf(
                'Attach at least one receipt — %s claims require evidence.',
                $category->name,
            ),
        ]);
    }

    private function finaliseApproval(Expense $expense, ?User $approver): void
    {
        $expense->status = Expense::STATUS_APPROVED;
        $expense->approved_at = now();
        $expense->final_approved_by = $approver?->id;
        $expense->current_approval_step = null;
        $expense->save();

        // Raised here rather than in approve(): this is the one place a
        // claim becomes approved, whether the answer came from the last
        // approver or from a workflow with nobody left to ask.
        event(new ExpenseDecided($expense, ExpenseDecided::APPROVED, $approver));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function categoryFor(array $data): ExpenseCategory
    {
        $category = ExpenseCategory::query()->find($data['expense_category_id'] ?? null);

        if ($category === null) {
            throw ValidationException::withMessages([
                'expense_category_id' => 'The selected expense category does not exist.',
            ]);
        }

        if (! $category->isActive()) {
            throw ValidationException::withMessages([
                'expense_category_id' => 'That expense category is no longer available.',
            ]);
        }

        return $category;
    }

    private function employeeFor(User $user): Employee
    {
        $employee = $user->employee;

        if ($employee === null) {
            abort(403, 'This account is not linked to an employee record.');
        }

        return $employee;
    }

    /**
     * The client's filename, kept as data and made harmless: directories,
     * quotes and line breaks are what a name would carry if it were ever
     * echoed into a header or a CSV cell, and none of them belong here.
     */
    private function originalName(UploadedFile $file): ?string
    {
        $name = basename(str_replace(['\\', "\0", "\r", "\n"], '/', (string) $file->getClientOriginalName()));

        $name = trim($name);

        return $name === '' ? null : mb_substr($name, 0, 255);
    }
}
