<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrainingProgramRequest;
use App\Http\Requests\UpdateTrainingProgramRequest;
use App\Http\Resources\TrainingProgramResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\TrainingProgram;
use App\Services\Training\TrainingProgramService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1/training-programs
 *
 * The training catalogue HR maintains — the four methods every other
 * catalogue in this application has, and no more.
 *
 * **There is no row scope here, and that is the point.** A program is
 * configuration, not a record about a person: every role that holds
 * `training.view` reads the same catalogue, and none of them reads it
 * differently. The narrow rules this module is known for live on
 * EmployeeTrainingController, where the row actually describes somebody.
 * Keeping the two controllers apart is what stops `training.update` — a
 * catalogue grant — from quietly becoming "may edit anybody's training
 * record".
 *
 * **There is no `destroy`.** A program with cohorts behind it must not be
 * removable, and one without any has nothing to gain from a hard delete
 * that `status = retired` does not already give. Retirement keeps every
 * enrolment the program produced readable — the courses cannot be un-run —
 * while taking it out of the assign form's picker.
 *
 * Writes go through TrainingProgramService, the seam the brief asks for so
 * Phase 12's audit logging attaches to one class rather than to two
 * endpoints.
 */
class TrainingProgramController extends Controller
{
    use BuildsResourceLists;

    public function __construct(
        private readonly TrainingProgramService $service,
    ) {}

    /**
     * GET /api/v1/training-programs
     *
     * Five filters, all answers a desk actually asks: which kind, which
     * state, does it issue a certificate, is it still offered, and what is
     * it called.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TrainingProgram::class);

        $query = TrainingProgram::query()->with('trainingType');

        $this->applyFilters($query, $request);

        $query->orderBy(
            $this->sortColumn($request, [
                'name', 'code', 'status', 'duration_days', 'created_at', 'updated_at',
            ], 'name'),
            $this->sortDirection($request, 'asc'),
        );

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Training programs.',
            TrainingProgramResource::collection($page),
            $page,
        );
    }

    /**
     * POST /api/v1/training-programs
     */
    public function store(StoreTrainingProgramRequest $request): JsonResponse
    {
        $program = $this->service->create($request->user(), $request->validated());
        $program->load('trainingType');

        return ApiResponse::created('Training program created.', new TrainingProgramResource($program));
    }

    /**
     * GET /api/v1/training-programs/{program}
     */
    public function show(Request $request, TrainingProgram $program): JsonResponse
    {
        $this->authorize('view', $program);

        $program->load('trainingType');

        return ApiResponse::success('Training program.', new TrainingProgramResource($program));
    }

    /**
     * PUT /api/v1/training-programs/{program}
     */
    public function update(UpdateTrainingProgramRequest $request, TrainingProgram $program): JsonResponse
    {
        $program = $this->service->update($request->user(), $program, $request->validated());
        $program->load('trainingType');

        return ApiResponse::success('Training program updated.', new TrainingProgramResource($program));
    }

    /**
     * @param  Builder<TrainingProgram>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($id = $this->param($request, 'training_type_id')) {
            $query->where('training_programs.training_type_id', (int) $id);
        }

        if ($status = $this->param($request, 'status')) {
            $wanted = array_values(array_intersect(
                array_filter(array_map('trim', explode(',', $status))),
                TrainingProgram::STATUSES,
            ));

            $query->whereIn('training_programs.status', $wanted ?: ['__none__']);
        }

        // `certificate_required=1` is how a compliance desk finds every
        // course that produces a card, which is the half of "who is
        // certified" that starts on the catalogue rather than on a person.
        if ($flag = $this->param($request, 'certificate_required')) {
            $truthy = in_array(strtolower($flag), ['1', 'true', 'yes'], true);
            $query->where('training_programs.certificate_required', $truthy);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';

            $query->where(function (Builder $inner) use ($like) {
                $inner->where('training_programs.name', 'like', $like)
                    ->orWhere('training_programs.code', 'like', $like)
                    ->orWhere('training_programs.provider', 'like', $like);
            });
        }
    }
}
