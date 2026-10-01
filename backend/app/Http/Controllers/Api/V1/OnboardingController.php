<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOnboardingRequest;
use App\Http\Resources\OnboardingChecklistItemResource;
use App\Http\Resources\OnboardingResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\Employee;
use App\Models\EmployeeOnboarding;
use App\Models\OnboardingRequirement;
use App\Services\Onboarding\OnboardingService;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/onboarding
 *
 * Where every starter stands, and what exactly is outstanding for one of
 * them. Two different questions, deliberately two different endpoints,
 * because they have wildly different costs:
 *
 *  - the **list** is a directory query. It reads `employees`, left with
 *    whatever onboarding row exists, so a person nobody has started still
 *    appears — as `draft`, with `exists = false`. The alternative, listing
 *    `employee_onboarding`, would quietly omit everybody untouched and an
 *    empty result would read as "all done" rather than "not begun".
 *
 *  - the **detail** is the only place the checklist is computed, because
 *    computing it costs a read of the file and a check of the bank record,
 *    and twenty rows on a page would cost twenty of each.
 *
 * The route takes `{employee}` rather than `{onboarding}` because that is
 * the question a caller asks — "where does *she* stand?" — while the policy
 * is resolved from an `EmployeeOnboarding` instance, staged unsaved where
 * none exists yet. See OnboardingController::record() for why staging
 * rather than creating matters.
 */
class OnboardingController extends Controller
{
    use BuildsResourceLists;

    public function __construct(private readonly OnboardingService $service) {}

    /**
     * GET /api/v1/onboarding
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeOnboarding::class);

        $query = Employee::query()->with(['onboarding', 'department', 'designation']);

        $query = Visibility::onboardingEmployeesFor($query, $request->user());

        $this->applyFilters($query, $request);

        $page = $query
            ->orderBy(
                $this->sortColumn($request, [
                    'first_name', 'last_name', 'employee_code', 'joining_date', 'created_at',
                ], 'first_name'),
                $this->sortDirection($request, 'asc'),
            )
            ->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Onboarding records.',
            OnboardingResource::collection($page),
            $page,
        );
    }

    /**
     * GET /api/v1/onboarding/{employee}
     *
     * The record, the full checklist, and what is holding completion up —
     * the four states scope item M asks HR to be able to distinguish are
     * the four non-`satisfied` values in `requirements`, with
     * `missing_requirements` as the short list of mandatory ones.
     */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', $this->record($employee));

        return ApiResponse::success('Onboarding record.', $this->detail($request, $employee));
    }

    /**
     * PUT /api/v1/onboarding/{employee}
     *
     * Moves the stage along, or completes it — the completion check is the
     * service's 409 naming what is outstanding, so a client that only knows
     * how to PUT can still finish the job without a second door to learn.
     */
    public function update(UpdateOnboardingRequest $request, Employee $employee): JsonResponse
    {
        $this->service->update($request->user(), $employee, $request->validated());

        return ApiResponse::success('Onboarding updated.', $this->detail($request, $employee));
    }

    /**
     * POST /api/v1/onboarding/{employee}/complete
     *
     * The same service method PUT reaches, offered as an action because
     * "complete onboarding" is a decision with a precondition rather than a
     * status somebody types — and because the 409 it raises is the only
     * response this endpoint can reasonably give when the file is still
     * short of something.
     */
    public function complete(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('complete', $this->record($employee));

        $this->service->complete($request->user(), $employee);

        return ApiResponse::success('Onboarding completed.', $this->detail($request, $employee));
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * The record to authorise against — existing, or staged unsaved.
     *
     * Staged rather than `firstOrCreate`ing: authorisation runs before we
     * know the caller may write, and a GET that materialises a row before
     * anybody has decided the caller may read it would be a write issued on
     * somebody else's behalf. EmployeeOnboardingPolicy reads the employee id and
     * nothing else, so an unsaved instance answers exactly as a saved one
     * would.
     */
    private function record(Employee $employee): EmployeeOnboarding
    {
        return $this->service->recordFor($employee);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Request $request, Employee $employee): array
    {
        $items = $this->service->checklist($employee);

        $satisfied = fn (array $item): bool => $item['state'] === OnboardingRequirement::STATE_SATISFIED;

        $unmet = $items
            ->filter(fn (array $item): bool => $item['requirement']->isMandatory() && ! $satisfied($item))
            ->values();

        $met = $items->filter($satisfied)->count();

        return (new OnboardingResource($employee))->toArray($request) + [
            'requirements' => OnboardingChecklistItemResource::collection($items->values())
                ->toArray($request),
            'missing_requirements' => $unmet
                ->map(fn (array $item): string => $item['requirement']->code)
                ->values()
                ->all(),
            'totals' => [
                'total' => $items->count(),
                'satisfied' => $met,
            ],
            // A record's own state, not the reader's permission: whether
            // *you* may press it is EmployeeOnboardingPolicy, asked when you do.
            'can_complete' => $unmet->isEmpty(),
        ];
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $status = $request->query('status');

        if (is_string($status) && trim($status) !== '') {
            $wanted = array_values(array_intersect(
                array_filter(array_map('trim', explode(',', $status))),
                EmployeeOnboarding::STATUSES,
            )) ?: ['__none__'];

            $draft = in_array(EmployeeOnboarding::STATUS_DRAFT, $wanted, true);
            $others = array_values(array_diff($wanted, [EmployeeOnboarding::STATUS_DRAFT, '__none__']));

            $query->where(function (Builder $outer) use ($draft, $others) {
                if ($others !== []) {
                    $outer->orWhereHas(
                        'onboarding',
                        fn (Builder $inner) => $inner->whereIn('status', $others),
                    );
                }

                if ($draft) {
                    // Nobody has opened it yet counts as `draft`, because
                    // that is exactly what it is — and filtering it out
                    // would hide the starters HR most needs to see.
                    $outer->orWhereDoesntHave('onboarding')
                        ->orWhereHas(
                            'onboarding',
                            fn (Builder $inner) => $inner->where('status', EmployeeOnboarding::STATUS_DRAFT),
                        );
                }
            });
        }

        if ($this->truthy($request, 'incomplete')) {
            $query->whereDoesntHave(
                'onboarding',
                fn (Builder $inner) => $inner->where('status', EmployeeOnboarding::STATUS_COMPLETED),
            );
        }

        if ($missing = $request->query('missing')) {
            if (is_string($missing) && trim($missing) !== '') {
                $this->service->constrainMissing($query, trim($missing));
            }
        }

        if ($term = trim((string) $request->query('search', ''))) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('employee_code', 'like', $like);
            });
        }
    }

    private function truthy(Request $request, string $key): bool
    {
        $value = $request->query($key);

        return $value !== null && in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }
}
