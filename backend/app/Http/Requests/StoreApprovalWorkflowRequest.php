<?php

namespace App\Http\Requests;

use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST|PUT /api/v1/approval-workflows
 *
 * A workflow is a chain of steps, submitted as an array and ordered by
 * `sequence` inside each element rather than by index in the array — so a
 * client may send them in any order and the sequence is still whatever the
 * numbers say.
 *
 * Each step resolves to an approver one of three ways:
 *
 *   approver_type = reporting_manager  the requester's own line manager;
 *                                      no other field applies
 *   approver_type = role               approver_role — everybody holding it
 *   approver_type = permission         approver_permission — everybody holding
 *
 * `approver_role` / `approver_permission` are required only when their own
 * `approver_type` says so, and that pairing is checked in `withValidator()`
 * rather than with `required_if` — Laravel does not expand the `*` in a
 * `required_if` comparison key, so `required_if:steps.*.approver_type,role`
 * silently reads a key that is never present and never fires.
 *
 * There is no `delete()` route and so no delete rule: a workflow referenced
 * by an in-flight request has to keep existing (the definition is read once,
 * at submit, and the chain is frozen there), and retiring one is
 * `status = inactive`.
 */
class StoreApprovalWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ApprovalWorkflow::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $route = $this->route('approvalWorkflow');
        $id = is_object($route) ? $route->id : $route;

        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:30', 'alpha_dash',
                Rule::unique('approval_workflows', 'code')->ignore($id),
            ],
            'subject_type' => ['required', Rule::in(ApprovalWorkflow::SUBJECTS)],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(ApprovalWorkflow::STATUSES)],

            'steps' => ['required', 'array', 'min:1', 'max:6'],
            'steps.*.sequence' => ['required', 'integer', 'min:1', 'max:6'],
            'steps.*.name' => ['nullable', 'string', 'max:100'],
            'steps.*.approver_type' => ['required', Rule::in(ApprovalWorkflowStep::TYPES)],
            'steps.*.approver_role' => ['nullable', 'string', 'max:100'],
            'steps.*.approver_permission' => [
                'nullable', 'string', 'max:100',
                'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/',
            ],
        ];
    }

    /**
     * Whole-array checks that no per-element rule can express.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $steps = $this->input('steps');

            if (! is_array($steps)) {
                return;
            }

            $sequences = array_filter(array_map(
                fn ($step) => is_array($step) ? ($step['sequence'] ?? null) : null,
                $steps,
            ));
            $sequences = array_values($sequences);

            // Sorted first, because the contract above says the array's own
            // order is irrelevant and only the numbers are the chain. The
            // sort does not weaken the check: a hole (1, 3), a duplicate
            // (1, 1) or a start other than 1 all still fail the range
            // comparison — they simply no longer fail *because* the client
            // listed step 2 before step 1.
            sort($sequences);

            // A hole in the sequence would make the runtime invent a step
            // number, and an invented approver is worse than a missing one.
            if ($sequences !== [] && $sequences !== range(1, count($sequences))) {
                $validator->errors()->add(
                    'steps',
                    'Step sequences must be consecutive numbers starting at 1.',
                );
            }

            $approvers = [];

            foreach ($steps as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $type = $step['approver_type'] ?? null;

                if ($type === ApprovalWorkflowStep::TYPE_ROLE) {
                    if (! isset($step['approver_role']) || $step['approver_role'] === '') {
                        $validator->errors()->add(
                            "steps.$index.approver_role",
                            'Name the role this step is waiting on.',
                        );
                    } else {
                        $approvers[] = 'role:'.$step['approver_role'];
                    }

                    continue;
                }

                if ($type === ApprovalWorkflowStep::TYPE_PERMISSION) {
                    if (! isset($step['approver_permission']) || $step['approver_permission'] === '') {
                        $validator->errors()->add(
                            "steps.$index.approver_permission",
                            'Name the permission this step is waiting on.',
                        );
                    } else {
                        $approvers[] = 'permission:'.$step['approver_permission'];
                    }

                    continue;
                }

                if ($type === ApprovalWorkflowStep::TYPE_REPORTING_MANAGER) {
                    // A manager step has no role to name — but naming one
                    // anyway means somebody intended a different type, and
                    // the runtime would quietly ignore the field.
                    if (! empty($step['approver_role']) || ! empty($step['approver_permission'])) {
                        $validator->errors()->add(
                            "steps.$index.approver_role",
                            'A reporting-manager step has no role or permission to name.',
                        );
                    }
                }
            }

            if (count($approvers) !== count(array_unique($approvers))) {
                $validator->errors()->add('steps', 'The same approver may not appear in two steps.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'steps.required' => 'A workflow needs at least one step.',
            'steps.min' => 'A workflow needs at least one step.',
            'steps.*.sequence.required' => 'Every step needs a sequence number.',
            'steps.*.approver_type.required' => 'Every step needs an approver type.',
            'steps.*.approver_type.in' => 'A step must resolve to a manager, a role or a permission.',
            'steps.*.approver_permission.regex' => 'A permission looks like leave.approve.',
        ];
    }
}
