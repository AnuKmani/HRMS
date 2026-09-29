<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActOnExpenseRequest;
use App\Http\Requests\StoreExpenseReceiptsRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Http\Resources\ExpenseReceiptResource;
use App\Http\Resources\ExpenseResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReceipt;
use App\Services\Expense\ExpenseReceiptStore;
use App\Services\Expense\ExpenseService;
use App\Support\Money;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/expenses
 *
 * The only controller that writes an expense claim, and it writes none of
 * it. Every transition goes through ExpenseService, so "which sequence can
 * turn a draft into a payment" has exactly one answer — this class decides
 * *who may ask*, the service decides *whether the ask is legal*.
 *
 * Authorisation is split exactly as leave and overtime split it:
 *
 *   - the coarse `permission:` middleware on each route is the door;
 *   - ExpensePolicy is the row: is this claim yours, are you the current
 *     approver, may you open this receipt;
 *   - the service is the state machine, and it answers 409 naming the state
 *     rather than 403, because "already submitted" and "you are not allowed"
 *     are different sentences and only one of them tells the caller what to
 *     do next.
 *
 * Nothing here reads a storage path into a response either — see
 * ExpenseResource and ExpenseReceiptResource for why no path ever leaves
 * the server.
 */
class ExpenseController extends Controller
{
    use BuildsResourceLists;

    public function __construct(
        private readonly ExpenseService $service,
        private readonly ExpenseReceiptStore $files,
    ) {}

    /* -------------------------------------------------------------- listing */

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $query = Visibility::expensesFor(
            Expense::query()
                ->with(['employee', 'category', 'project', 'site'])
                ->withCount('receipts'),
            $request->user(),
        );

        $this->applyFilters($query, $request);

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where('description', 'like', $like);
        }

        $page = $query->orderBy(
            $this->sortColumn($request, ['expense_date', 'amount', 'status', 'created_at'], 'expense_date'),
            $this->sortDirection($request, 'desc'),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Expenses retrieved.',
            ExpenseResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        // The chain and the evidence are loaded here and only here: a list
        // of fifty rows each pulling a sequence of approval records is the
        // textbook N+1, and a list screen has nowhere to put them anyway.
        $expense->load(['employee', 'category', 'project', 'site', 'receipts', 'approvalRecords.actor']);

        return ApiResponse::success(
            'Expense retrieved.',
            new ExpenseResource($expense),
        );
    }

    /* --------------------------------------------------------------- writes */

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->service->create($request->user(), $request->validated());
        $expense->load(['employee', 'category', 'project', 'site', 'receipts']);

        return ApiResponse::created('Expense created.', new ExpenseResource($expense));
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->service->update(
            $request->user(),
            $expense,
            $request->validated(),
        );

        $expense->load(['employee', 'category', 'project', 'site', 'receipts']);

        return ApiResponse::success('Expense updated.', new ExpenseResource($expense));
    }

    public function submit(ActOnExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('submit', $expense);

        $expense = $this->service->submit($request->user(), $expense);

        return ApiResponse::success('Expense submitted.', new ExpenseResource($expense));
    }

    public function approve(ActOnExpenseRequest $request, Expense $expense): JsonResponse
    {
        // The policy asks ApprovalWorkflowService whether *this* user is the
        // approver of *this* claim's *current* link. The service asks it
        // again under a row lock. Both, deliberately: one gives a clean 403
        // before any work happens, the other is what actually protects the
        // write against a second approval racing in.
        $this->authorize('approve', $expense);

        $expense = $this->service->approve(
            $request->user(),
            $expense,
            (string) $request->input('remarks', ''),
        );

        return ApiResponse::success('Expense approved.', new ExpenseResource($expense));
    }

    public function reject(ActOnExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('reject', $expense);

        $expense = $this->service->reject(
            $request->user(),
            $expense,
            (string) $request->input('remarks', ''),
        );

        return ApiResponse::success('Expense rejected.', new ExpenseResource($expense));
    }

    public function cancel(ActOnExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('cancel', $expense);

        $expense = $this->service->cancel($request->user(), $expense);

        return ApiResponse::success('Expense cancelled.', new ExpenseResource($expense));
    }

    /* ------------------------------------------------------------ receipts */

    /**
     * POST /api/v1/expenses/{expense}/receipts
     *
     * A batch of evidence onto a draft. The bytes are validated, the name is
     * generated by the server, the file lands on the private `local` disk
     * under a directory no route serves statically, and the path stays on
     * the row rather than being handed back.
     */
    public function storeReceipts(StoreExpenseReceiptsRequest $request, Expense $expense): JsonResponse
    {
        $expense = $this->service->addReceipts(
            $request->user(),
            $expense,
            $request->file('receipts', []),
        );

        return ApiResponse::success(
            'Receipts attached.',
            new ExpenseResource($expense->loadMissing(['employee', 'category', 'project', 'site'])),
        );
    }

    /**
     * GET /api/v1/expenses/{expense}/receipts/{receipt}
     *
     * The only route to a stored receipt, and like the medical certificate
     * it makes you name the claim first: you cannot list receipts, cannot
     * guess a path, and cannot fetch one for a claim you could not already
     * read. `no-store` in ExpenseReceiptStore so a shared device does not
     * keep an invoice in its browser cache.
     */
    public function receipt(Request $request, Expense $expense, ExpenseReceipt $receipt): StreamedResponse
    {
        $this->authorize('viewReceipt', [$expense, $receipt]);

        $response = $this->files->response($receipt->path);

        if ($response === null) {
            abort(404, 'That receipt is no longer available.');
        }

        return $response;
    }

    public function deleteReceipt(Request $request, Expense $expense, ExpenseReceipt $receipt): JsonResponse
    {
        $this->authorize('deleteReceipt', [$expense, $receipt]);

        $this->service->removeReceipt($request->user(), $expense, $receipt);

        // Back with the claim rather than a bare acknowledgement: the app
        // just changed a *count* it is showing, and `load()` rather than
        // `loadMissing()` because the relation it holds predates the delete.
        return ApiResponse::success(
            'Receipt removed.',
            new ExpenseResource($expense->loadMissing(['employee', 'category', 'project', 'site'])->load('receipts')),
        );
    }

    /* ----------------------------------------------------------- reference */

    /**
     * GET /api/v1/expense-categories
     *
     * Read-only on purpose. A category is configuration — the two rules a
     * claim has to satisfy live on the row — and this endpoint hands the
     * form the pickable list plus those rules. Maintaining the list is a
     * seeding/administration job rather than a module of its own, so there
     * is deliberately no POST, PUT or DELETE here: adding a category means
     * adding a row, not adding an endpoint.
     */
    public function categories(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $categories = ExpenseCategory::query()
            ->when($this->param($request, 'all') === null, fn ($query) => $query->active())
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            'Expense categories retrieved.',
            ExpenseCategoryResource::collection($categories),
        );
    }

    /* -------------------------------------------------------------- summary */

    /**
     * GET /api/v1/expenses/summary
     *
     * Four totals and nothing else: by status, by category, by project, and
     * the date range they were over. Deliberately not an analytics surface —
     * a screen asking "how much is waiting, and how much landed?" needs
     * exactly this, and a second aggregate would be a second query nobody
     * asked for.
     *
     * Scoped through the same Visibility::expensesFor() as the list, so an
     * employee's summary is their own claims and a Project Manager's is
     * their workforce's. The collection endpoint is the easier one to hit;
     * a summary that answered wider than the list would be the same hole
     * wearing a different hat.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $base = Visibility::expensesFor(Expense::query(), $request->user());
        $this->applyFilters($base, $request);

        $byStatus = [];
        $byCategory = [];
        $byProject = [];

        foreach ((clone $base)->selectRaw('status, COUNT(*) as row_count, COALESCE(SUM(amount), 0) as total_amount')->groupBy('status')->get() as $row) {
            $byStatus[$row->status] = [
                'count' => (int) $row->row_count,
                'amount' => Money::decimal($row->total_amount),
            ];
        }

        foreach ((clone $base)->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->selectRaw('expenses.expense_category_id, expense_categories.name as category_name, COUNT(*) as row_count, COALESCE(SUM(expenses.amount), 0) as total_amount')
            ->groupBy('expenses.expense_category_id', 'expense_categories.name')
            ->get() as $row) {
            $byCategory[] = [
                'expense_category_id' => $row->expense_category_id,
                'name' => $row->category_name,
                'count' => (int) $row->row_count,
                'amount' => Money::decimal($row->total_amount),
            ];
        }

        foreach ((clone $base)->selectRaw('project_id, COUNT(*) as row_count, COALESCE(SUM(amount), 0) as total_amount')->groupBy('project_id')->get() as $row) {
            $byProject[] = [
                'project_id' => $row->project_id,
                'count' => (int) $row->row_count,
                'amount' => Money::decimal($row->total_amount),
            ];
        }

        // The five states are enumerated so a screen can print "AED 0.00"
        // for rejected rather than deciding what a missing key means.
        $statuses = [];

        foreach (Expense::STATUSES as $status) {
            $statuses[$status] = $byStatus[$status] ?? ['count' => 0, 'amount' => '0.00'];
        }

        return ApiResponse::success('Expense summary retrieved.', (object) [
            'from' => $this->param($request, 'from', 'date_from'),
            'to' => $this->param($request, 'to', 'date_to'),
            'by_status' => (object) $statuses,
            'by_category' => $byCategory,
            'by_project' => $byProject,
        ]);
    }

    /* ------------------------------------------------------------- internals */

    /**
     * One filter per business question, each narrowing rather than
     * replacing, so "my site's travel claims in March" is the intersection
     * without any of the three having to know about the other two.
     *
     * @param  Builder<Expense>  $query
     */
    private function applyFilters($query, Request $request): void
    {
        if ($statuses = $this->param($request, 'status')) {
            $query->whereIn('status', array_filter(explode(',', $statuses)));
        }

        if ($employeeId = $this->param($request, 'employee_id')) {
            $query->where('employee_id', (int) $employeeId);
        }

        if ($projectId = $this->param($request, 'project_id')) {
            $query->where('project_id', (int) $projectId);
        }

        if ($siteId = $this->param($request, 'site_id')) {
            $query->where('site_id', (int) $siteId);
        }

        if ($categoryId = $this->param($request, 'category', 'category_id', 'expense_category_id')) {
            $query->where('expense_category_id', (int) $categoryId);
        }

        // Containment, not "within": a claim made during a fortnight that
        // started a week early belongs to the view it was asked for.
        if ($from = $this->param($request, 'from', 'date_from')) {
            $query->where('expense_date', '>=', $from);
        }

        if ($to = $this->param($request, 'to', 'date_to')) {
            $query->where('expense_date', '<=', $to);
        }
    }
}
