<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApprovalWorkflowRequest;
use App\Http\Requests\UpdateApprovalWorkflowRequest;
use App\Http\Resources\ApprovalWorkflowResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\ApprovalWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/approval-workflows
 *
 * The configuration of who may say yes to what — which is why it carries two
 * permissions rather than one: `approvals.view` to see why a request went
 * where it went, `approvals.manage` to decide where it will go next.
 *
 * The steps are written here rather than in a service because they are
 * configuration, not a transaction over anything else. What matters is that
 * they are written *atomically*: a workflow saved with a half-replaced chain
 * would leave a request able to start at step 2 of a chain nobody designed.
 *
 * No `destroy()`. A workflow referenced by an in-flight request has to keep
 * existing — ApprovalWorkflowService reads the definition once, at submit,
 * and the chain is frozen from there — and retiring one is
 * `status = inactive`.
 */
class ApprovalWorkflowController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApprovalWorkflow::class);

        $query = ApprovalWorkflow::query()->with('steps');

        if ($subject = $this->param($request, 'subject_type')) {
            $query->where('subject_type', $subject);
        }

        if (($status = $this->param($request, 'status')) !== null) {
            $query->where('status', $status);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        $page = $query->orderBy(
            $this->sortColumn($request, ['name', 'code', 'subject_type', 'created_at'], 'name'),
            $this->sortDirection($request),
        )->orderBy('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Approval workflows retrieved.',
            ApprovalWorkflowResource::collection($page->getCollection()),
            $page,
        );
    }

    public function show(Request $request, ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $this->authorize('view', $approvalWorkflow);

        return ApiResponse::success(
            'Approval workflow retrieved.',
            new ApprovalWorkflowResource($approvalWorkflow->load('steps')),
        );
    }

    public function store(StoreApprovalWorkflowRequest $request): JsonResponse
    {
        // `steps` is pulled out here exactly as update() pulls it out. Left
        // inside $data it would ride along as an attribute `write()` never
        // looks at, and the workflow would be created *without a chain* — a
        // 201 for a definition that `forSubject()` later refuses as "has no
        // steps". Same shape, both entry points, for the same reason.
        $data = $request->validated();
        $steps = $data['steps'] ?? null;
        unset($data['steps']);

        $workflow = $this->write($data, null, $steps);

        return ApiResponse::created(
            'Approval workflow created.',
            new ApprovalWorkflowResource($workflow->load('steps')),
        );
    }

    public function update(UpdateApprovalWorkflowRequest $request, ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $data = $request->validated();
        $steps = $data['steps'] ?? null;
        unset($data['steps']);

        $workflow = $this->write($data, $approvalWorkflow, $steps);

        return ApiResponse::success(
            'Approval workflow updated.',
            new ApprovalWorkflowResource($workflow->load('steps')),
        );
    }

    /**
     * One transaction for the definition and its chain.
     *
     * A partial `steps` is not merged — see the class note — so the chain is
     * only touched when the payload actually carries one.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>|null  $steps
     */
    private function write(array $data, ?ApprovalWorkflow $workflow = null, ?array $steps = null): ApprovalWorkflow
    {
        return DB::transaction(function () use ($data, $workflow, $steps) {
            if ($workflow === null) {
                $workflow = new ApprovalWorkflow($data);
                $workflow->save();
            } else {
                $workflow->fill($data)->save();
            }

            // Exactly one default per subject: `scopeDefaultFor()` picks the
            // chain requests fall back to, and two rows with `is_default`
            // would make that lookup return whichever the database liked —
            // an approval route decided by an index scan.
            if ($workflow->is_default) {
                ApprovalWorkflow::query()
                    ->where('subject_type', $workflow->subject_type)
                    ->whereKeyNot($workflow->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            if ($steps !== null) {
                // Delete then insert rather than an upsert keyed on
                // sequence: a step's identity is its place in the chain, so
                // renumbering steps is the normal case, and keeping ids
                // alive across a renumber would preserve links nothing
                // references.
                $workflow->steps()->delete();

                foreach ($steps as $step) {
                    $workflow->steps()->create([
                        'sequence' => (int) $step['sequence'],
                        // `name` is NOT NULL on the column and the API lets it
                        // be omitted, so it is derived from what the step
                        // actually is. A screen showing "HR Admin" rather than
                        // a blank row is the better failure either way.
                        'name' => $this->stepName($step),
                        'approver_type' => $step['approver_type'],
                        'approver_role' => $step['approver_role'] ?? null,
                        'approver_permission' => $step['approver_permission'] ?? null,
                    ]);
                }
            }

            return $workflow;
        });
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function stepName(array $step): string
    {
        $given = trim((string) ($step['name'] ?? ''));

        if ($given !== '') {
            return mb_substr($given, 0, 100);
        }

        $derived = match ($step['approver_type'] ?? null) {
            'role' => (string) ($step['approver_role'] ?? 'Role approver'),
            'permission' => (string) ($step['approver_permission'] ?? 'Permission approver'),
            default => 'Reporting manager',
        };

        return mb_substr($derived, 0, 100);
    }
}
