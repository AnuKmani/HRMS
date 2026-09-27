<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MakesRequiredRulesOptional;
use App\Models\ApprovalWorkflow;

/**
 * PUT /api/v1/approval-workflows/{approvalWorkflow}
 *
 * Same table as creating, relaxed to `sometimes`. The steps array, if sent,
 * replaces the chain wholesale rather than merging into it: a half-updated
 * approval chain is the kind of thing that is very hard to notice and very
 * expensive to notice later, and an editor that has loaded the workflow has
 * the whole chain in hand anyway.
 */
class UpdateApprovalWorkflowRequest extends StoreApprovalWorkflowRequest
{
    use MakesRequiredRulesOptional;

    public function authorize(): bool
    {
        $workflow = $this->route('approvalWorkflow');

        if (! $workflow instanceof ApprovalWorkflow) {
            return parent::authorize();
        }

        return $this->user()?->can('update', $workflow) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->relaxRequired(parent::rules());
    }
}
