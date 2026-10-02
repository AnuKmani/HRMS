<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelEmployeeTrainingRequest;
use App\Http\Requests\CompleteEmployeeTrainingRequest;
use App\Http\Requests\StoreEmployeeTrainingRequest;
use App\Http\Requests\UpdateEmployeeTrainingRequest;
use App\Http\Resources\EmployeeTrainingResource;
use App\Http\Resources\TrainingProgramResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\EmployeeTraining;
use App\Models\TrainingProgram;
use App\Services\Documents\EmployeeDocumentStore;
use App\Services\Training\EmployeeTrainingService;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /api/v1/employee-training
 *
 * One person's place on one course — and the only controller that writes
 * one, and it writes none of it. Every transition goes through
 * EmployeeTrainingService, so "which sequence can turn an enrolment into an
 * expired certificate, and what has to be true at each step" has exactly one
 * answer — this class decides *who may ask*, the service decides *whether
 * the ask is legal*.
 *
 * Authorisation is split the way documents, leave and expenses split it:
 *
 *   - the coarse `permission:` middleware on each route is the door;
 *   - EmployeeTrainingPolicy is the row: is this yours, may you complete it,
 *     may you open the certificate behind it;
 *   - the service is the state machine, and it answers 409 naming the state
 *     rather than 403, because "already completed" and "you are not allowed"
 *     are different sentences and only one tells the caller what to do next.
 *
 * The row scope is Visibility's, asked here for the list and in the policy
 * for the single record — the collection is the easier endpoint to hit, so a
 * policy that refused one record while the index returned the other would be
 * no boundary at all.
 *
 * Four routes are worth a note:
 *
 *  - **`expiring` before `{training}`**, so `expiring` is a word and not an
 *    id — belt and braces beside the `whereNumber` on `{training}`, because
 *    a route declared the other way round still has to be read correctly by
 *    a human.
 *  - **`complete` and `cancel` are POSTs, not a PUT with a status.** Each is
 *    a distinct act with its own permission and its own refusal, and a
 *    `status` field in a payload is a second way to say the same thing and
 *    a first way to say a different one.
 *  - **`file` streams.** The certificate's bytes leave through
 *    EmployeeDocumentStore exactly as a passport's do, behind
 *    `viewCertificate` — a *different* answer from the row, because being
 *    handed a list of courses somebody sat is not the same act as being
 *    handed the card that came out of one.
 *  - **`compliance` reports, and reports about what?** See the method.
 */
class EmployeeTrainingController extends Controller
{
    use BuildsResourceLists;

    public function __construct(
        private readonly EmployeeTrainingService $service,
        private readonly EmployeeDocumentStore $files,
    ) {}

    /**
     * GET /api/v1/employee-training
     *
     * Six filters: whose, which program, which state, has it been passed,
     * is its certificate dead, is it about to be.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeTraining::class);

        $query = EmployeeTraining::query()
            ->with(['trainingProgram.trainingType', 'employee'])
            ->select('employee_trainings.*');

        // Joined unconditionally rather than when a filter needs it: it is
        // a many-to-one, so it can never duplicate a row, and having one
        // alias in play is what keeps a `status` filter from colliding with
        // `training_programs.status` the first time both are asked for.
        $query->join('training_programs', 'training_programs.id', '=', 'employee_trainings.training_program_id');

        $query = Visibility::employeeTrainingsFor($query, $request->user());

        $this->applyFilters($query, $request);

        $query->orderBy(
            $this->sortColumn($request, [
                'enrollment_date', 'training_date', 'completion_date',
                'certificate_expiry_date', 'status', 'created_at',
            ], 'enrollment_date'),
            $this->sortDirection($request, 'desc'),
        );

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Training records.',
            EmployeeTrainingResource::collection($page),
            $page,
        );
    }

    /**
     * POST /api/v1/employee-training
     *
     * The FormRequest already decided *who for* — `training.assign`, with no
     * self-service half, because putting yourself on the course that
     * certifies you is exactly the act the brief rules out — and resolved
     * the target before a byte of the payload was read.
     */
    public function store(StoreEmployeeTrainingRequest $request): JsonResponse
    {
        $employee = $request->target();

        abort_unless($employee !== null, 403, 'You may not enrol that employee.');

        $training = $this->service->assign($request->user(), $employee, $request->trainingPayload());

        return ApiResponse::created('Training assigned.', new EmployeeTrainingResource($training));
    }

    /**
     * GET /api/v1/employee-training/expiring
     *
     * The cross-employee "whose certificate is about to lapse" view, behind
     * its own permission because the question is about everybody rather than
     * about you. Rows are still scoped by Visibility, so a holder of
     * `training.expiry.view` without `training.manage` sees their own course
     * history and nobody else's — the permission opens the door, it does not
     * hand over the room.
     *
     * `within` widens the window from "already gone" to "soon", in days,
     * capped so one request cannot ask for the whole future, and it is
     * applied as a *single* comparison so it cannot escape the row scope
     * appended after it.
     */
    public function expiring(Request $request): JsonResponse
    {
        $this->authorize('expiryReport', EmployeeTraining::class);

        $within = (int) min(max((int) $request->query('within', 0), 0), 730);
        $until = $within > 0 ? now()->addDays($within)->toDateString() : now()->toDateString();

        $query = EmployeeTraining::query()
            ->with(['trainingProgram.trainingType', 'employee'])
            ->select('employee_trainings.*')
            ->whereNotNull('employee_trainings.certificate_expiry_date')
            ->where('employee_trainings.certificate_expiry_date', '<=', $until)
            // Cancelled and failed rows have no certificate at all, and a
            // `completed` row whose card has *already* lapsed belongs in the
            // expired bucket rather than here — but it is still listed, so
            // a desk chasing overdue cards is not told the list is empty.
            ->whereNotIn('employee_trainings.status', [
                EmployeeTraining::STATUS_CANCELLED,
                EmployeeTraining::STATUS_FAILED,
            ])
            ->orderBy('employee_trainings.certificate_expiry_date')
            ->orderBy('employee_trainings.id');

        $query = Visibility::employeeTrainingsFor($query, $request->user());

        $page = $query->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Training certificates due to expire.',
            EmployeeTrainingResource::collection($page),
            $page,
        );
    }

    /**
     * GET /api/v1/employee-training/{training}
     */
    public function show(Request $request, EmployeeTraining $training): JsonResponse
    {
        $this->authorize('view', $training);

        $training->load(['trainingProgram.trainingType', 'employee']);

        return ApiResponse::success('Training record.', new EmployeeTrainingResource($training));
    }

    /**
     * PUT /api/v1/employee-training/{training}
     */
    public function update(UpdateEmployeeTrainingRequest $request, EmployeeTraining $training): JsonResponse
    {
        $training = $this->service->update($request->user(), $training, $request->trainingPayload());
        $training->load(['trainingProgram.trainingType', 'employee']);

        return ApiResponse::success('Training record updated.', new EmployeeTrainingResource($training));
    }

    /**
     * POST /api/v1/employee-training/{training}/complete
     */
    public function complete(CompleteEmployeeTrainingRequest $request, EmployeeTraining $training): JsonResponse
    {
        $training = $this->service->complete($request->user(), $training, $request->completionPayload());
        $training->load(['trainingProgram.trainingType', 'employee']);

        return ApiResponse::success('Training completed.', new EmployeeTrainingResource($training));
    }

    /**
     * POST /api/v1/employee-training/{training}/cancel
     *
     * Cancels rather than deletes: who was booked on what, and that it did
     * not happen, is a fact a later audit wants, and a row that vanished
     * would leave the seat unexplained.
     */
    public function cancel(CancelEmployeeTrainingRequest $request, EmployeeTraining $training): JsonResponse
    {
        $training = $this->service->cancel(
            $request->user(),
            $training,
            $request->cancelPayload(),
        );
        $training->load(['trainingProgram.trainingType', 'employee']);

        return ApiResponse::success('Training cancelled.', new EmployeeTrainingResource($training));
    }

    /**
     * GET /api/v1/employee-training/{training}/file
     *
     * The one way to the bytes, and a *different* gate from the row it
     * belongs to: your own card needs only `training.view` (an employee
     * showing a site supervisor their working-at-heights card), a
     * colleague's needs `training.certificates.view`, which is HR's alone.
     * `training.manage` does not open it — see
     * Visibility::maySeeCertificateFor() for why that asymmetry is the
     * point.
     */
    public function file(Request $request, EmployeeTraining $training): StreamedResponse
    {
        $this->authorize('viewCertificate', $training);

        $response = $this->files->response(
            $training->certificate_path,
            $training->certificate_original_name,
        );

        abort_if($response === null, 404, 'That training record has no certificate attached.');

        return $response;
    }

    /**
     * GET /api/v1/training-compliance
     *
     * One screen's worth of answers, built as three small summaries rather
     * than one clever one:
     *
     *  - **programs** — the catalogue with a headcount behind each, so
     *    "which courses do we actually run" and "who is on them" are one
     *    look;
     *  - **enrollments by status** — the whole workforce's state in seven
     *    buckets, which is the "are we compliant" number;
     *  - **certificates by state** — `valid` / `expiring_soon` / `expired` /
     *    `none`, computed from *today* rather than from the stored status so
     *    a lagging scheduler cannot make this report optimistic.
     *
     * Every count is scoped by Visibility before it is counted: a PM who may
     * read only their own rows gets their own numbers, and the report never
     * becomes a window onto the whole workforce for somebody holding
     * `training.view` alone. That is the difference between a *summary* and a
     * leak — the totals are the most tempting thing to compute before the
     * scope is applied, and are therefore computed after it.
     *
     * `expiring_soon` is deliberately not a stored state. It is a window
     * over `certificate_expiry_date`, computed here the same way
     * EmployeeTraining::certificateExpiryState() computes it, because a
     * column that says "expiring soon" is right today and wrong tomorrow.
     */
    public function compliance(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeTraining::class);

        // Scoped *before* anything is counted, and re-derived rather than
        // cloned: `Visibility::employeeTrainingsFor()` is a pure predicate,
        // and asking it three times on three fresh queries is cheaper to
        // reason about than cloning a builder whose query-builder and
        // eager-load halves do not clone together.
        $scoped = fn () => Visibility::employeeTrainingsFor(
            EmployeeTraining::query()->select('employee_trainings.*'),
            $request->user(),
        );

        $rows = $scoped()->get([
            'training_program_id',
            'status',
            'certificate_expiry_date',
        ]);

        $statuses = array_fill_keys(EmployeeTraining::STATUSES, 0);
        $certificates = ['valid' => 0, 'expiring_soon' => 0, 'expired' => 0, 'none' => 0];
        $perProgram = [];
        $activePerProgram = [];

        $warning = max(0, (int) config('hrms.expiry.default_warning_days', 30));
        $today = now()->toDateString();
        $soon = now()->addDays($warning)->toDateString();

        foreach ($rows as $row) {
            if (array_key_exists($row->status, $statuses)) {
                $statuses[$row->status]++;
            }

            $perProgram[$row->training_program_id] = ($perProgram[$row->training_program_id] ?? 0) + 1;

            if (in_array($row->status, EmployeeTraining::LIVE, true)) {
                $activePerProgram[$row->training_program_id] = ($activePerProgram[$row->training_program_id] ?? 0) + 1;
            }

            // `expired` is the only status that has already lost its card
            // and `completed` the only other one that can hold one — every
            // other status answers `none`, because reporting a certificate
            // for a course somebody never sat would be a bug that reads as
            // a fact.
            if ($row->status === EmployeeTraining::STATUS_EXPIRED) {
                $certificates['expired']++;

                continue;
            }

            if ($row->status !== EmployeeTraining::STATUS_COMPLETED || $row->certificate_expiry_date === null) {
                $certificates['none']++;

                continue;
            }

            $expires = $row->certificate_expiry_date->toDateString();

            if ($expires < $today) {
                $certificates['expired']++;
            } elseif ($expires <= $soon) {
                $certificates['expiring_soon']++;
            } else {
                $certificates['valid']++;
            }
        }

        // The catalogue itself is *not* narrowed — an operator needs to see
        // the courses they run, even the ones nobody has sat — but every
        // count hanging off it is, which is why the numbers are tallied
        // above from the scoped rows rather than taken from `withCount()`.
        // A summary computed before the row scope is applied is the most
        // tempting way for this endpoint to become a window onto the whole
        // workforce for somebody holding `training.view` alone.
        $programs = TrainingProgram::query()
            ->with('trainingType')
            ->orderBy('name')
            ->get()
            ->map(function (TrainingProgram $program) use ($request, $perProgram, $activePerProgram) {
                $payload = (new TrainingProgramResource($program))->toArray($request);
                $payload['enrollments_count'] = $perProgram[$program->id] ?? 0;
                $payload['active_enrollments_count'] = $activePerProgram[$program->id] ?? 0;

                return $payload;
            });

        return ApiResponse::success('Training compliance.', (object) [
            'generated_at' => now()->toIso8601String(),
            'warning_days' => $warning,
            'programs' => $programs,
            'enrollments_by_status' => $statuses,
            'certificates' => $certificates,
            'totals' => (object) [
                'enrollments' => array_sum($statuses),
                'certificates' => array_sum($certificates) - $certificates['none'],
            ],
        ]);
    }

    /**
     * @param  Builder<EmployeeTraining>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($id = $this->param($request, 'employee_id')) {
            $query->where('employee_trainings.employee_id', (int) $id);
        }

        if ($id = $this->param($request, 'training_program_id')) {
            $query->where('employee_trainings.training_program_id', (int) $id);
        }

        if ($id = $this->param($request, 'training_type_id')) {
            $query->where('training_programs.training_type_id', (int) $id);
        }

        if ($status = $this->param($request, 'status')) {
            // Comma-separated, because a desk wanting "scheduled or in
            // progress" should not have to make two requests and join them.
            $wanted = array_values(array_intersect(
                array_filter(array_map('trim', explode(',', $status))),
                EmployeeTraining::STATUSES,
            ));

            $query->whereIn('employee_trainings.status', $wanted ?: ['__none__']);
        }

        if ($flag = $this->param($request, 'certified')) {
            $truthy = in_array(strtolower($flag), ['1', 'true', 'yes'], true);

            $truthy
                ? $query->whereNotNull('employee_trainings.certificate_issue_date')
                : $query->whereNull('employee_trainings.certificate_issue_date');
        }

        if ($this->flagged($request, 'expired')) {
            // Driven by the *date*, not by the stored `expired` status: a
            // certificate whose date passed an hour ago is expired whether
            // or not the scheduler has run, and a lagging cron must not make
            // this filter tell a lie.
            $query->whereNotNull('employee_trainings.certificate_expiry_date')
                ->where('employee_trainings.certificate_expiry_date', '<', now()->toDateString());
        }

        if ($this->flagged($request, 'expiring_soon')) {
            $warning = max(0, (int) config('hrms.expiry.default_warning_days', 30));

            $query->whereNotNull('employee_trainings.certificate_expiry_date')
                ->where('employee_trainings.certificate_expiry_date', '>=', now()->toDateString())
                ->where('employee_trainings.certificate_expiry_date', '<=', now()->addDays($warning)->toDateString());
        }

        if ($from = $this->param($request, 'enrolled_from')) {
            $query->where('employee_trainings.enrollment_date', '>=', $from);
        }

        if ($to = $this->param($request, 'enrolled_to')) {
            $query->where('employee_trainings.enrollment_date', '<=', $to);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';

            $query->join('employees', 'employees.id', '=', 'employee_trainings.employee_id')
                ->where(function (Builder $inner) use ($like) {
                    $inner->where('employees.first_name', 'like', $like)
                        ->orWhere('employees.last_name', 'like', $like)
                        ->orWhere('employee_trainings.certificate_number', 'like', $like)
                        ->orWhere('training_programs.name', 'like', $like);
                });
        }
    }

    private function flagged(Request $request, string $key): bool
    {
        $value = $this->param($request, $key);

        return $value !== null && in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }
}
