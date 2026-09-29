<?php

namespace App\Http\Resources;

use App\Models\Expense;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One expense claim, in the shape the app renders.
 *
 * Three things this payload is careful about:
 *
 *  - **`path` never appears.** The storage path of a receipt is exactly the
 *    detail that should not leave the server — it says where private files
 *    live and hands a reader something to guess at. Receipts go out as
 *    {@see ExpenseReceiptResource} rows and are fetched only through
 *    `GET /expenses/{expense}/receipts/{receipt}`, behind the policy that
 *    already governs the claim.
 *
 *  - **money is a string with two decimals, never a float.** `amount` is
 *    DECIMAL(12,2) in the column and `Money::decimal()` in the payload, so
 *    "33.30" arrives as "33.30" rather than as 33.299999999999997.
 *
 *  - **state flags are facts about the record, not about the reader.**
 *    `is_draft` and `is_open` are the record's own words; whether *you* may
 *    edit, submit or approve it is ExpensePolicy's answer, asked when you
 *    try — the same reason {@see SiteActivityReportResource} ships
 *    `is_editable` and pointedly no `can_edit`.
 *
 * `requires_receipt` and `maximum_amount` are emitted because they are the
 * two category rules a form has to honour *before* the server can be asked
 * to accept a claim: a screen that did not know "Site Expense needs paper"
 * would let somebody finish a form that was never going to be submittable.
 *
 * `approval_chain` is opt-in so a list of fifty rows does not silently
 * issue fifty sequences of queries.
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Expense $resource */
        $resource = $this->resource;

        return [
            'id' => $resource->id,

            'employee_id' => $resource->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => new EmployeeBriefResource($resource->employee)),

            'expense_category_id' => $resource->expense_category_id,
            'category' => $this->whenLoaded(
                'category',
                fn () => $resource->category
                    ? new ExpenseCategoryResource($resource->category)
                    : null,
            ),

            'project_id' => $resource->project_id,
            'project' => $this->whenLoaded('project', fn () => $resource->project
                ? ['id' => $resource->project->id, 'name' => $resource->project->name, 'code' => $resource->project->code]
                : null),

            'site_id' => $resource->site_id,
            'site' => $this->whenLoaded('site', fn () => $resource->site
                ? ['id' => $resource->site->id, 'name' => $resource->site->name, 'code' => $resource->site->code]
                : null),

            'expense_date' => $resource->expense_date?->toDateString(),

            'amount' => Money::decimal($resource->amount),
            'currency' => $resource->currency,
            'description' => $resource->description,

            'status' => $resource->status,
            'is_draft' => $resource->isDraft(),
            'is_open' => $resource->isOpen(),
            'summary' => $resource->summary(),

            // The two category rules, read off the row's own category. Null
            // only when the relation was deliberately not loaded, which no
            // endpoint does — the form and the submit button both need them.
            'requires_receipt' => $resource->relationLoaded('category')
                ? $resource->category?->requires_receipt
                : null,
            'maximum_amount' => $resource->relationLoaded('category')
                ? $resource->category?->maximum_amount
                : null,

            // Metadata for the list; the bytes are behind the policy-checked
            // receipt route and only ever fetched by id.
            'receipt_count' => $resource->relationLoaded('receipts')
                ? $resource->receipts->count()
                : ($resource->receipts_count ?? null),
            'receipts' => $this->whenLoaded(
                'receipts',
                fn () => ExpenseReceiptResource::collection($resource->receipts),
            ),

            'current_approval_step' => $resource->current_approval_step,
            'approval_workflow_id' => $resource->approval_workflow_id,
            'approval_chain' => $this->whenLoaded(
                'approvalRecords',
                fn () => ApprovalRecordResource::collection($resource->approvalRecords),
            ),

            'submitted_at' => $resource->submitted_at?->toIso8601String(),
            'approved_at' => $resource->approved_at?->toIso8601String(),
            'rejected_at' => $resource->rejected_at?->toIso8601String(),
            'cancelled_at' => $resource->cancelled_at?->toIso8601String(),
            'final_approved_by' => $resource->final_approved_by,

            'created_at' => $resource->created_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
