<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\BuildsResourceLists;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingTypeResource;
use App\Http\Responses\ApiResponse;
use App\Models\TrainingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/training-types
 *
 * The vocabulary of *kinds* of training, and the whole controller is one
 * index method.
 *
 * It is unpaginated on purpose. This is the table behind every training
 * form's picker — a handful of rows that change when an operator adds one,
 * not a collection that grows — and asking a screen that must draw every
 * option to also draw a pager would be asking the wrong question. The list
 * is small enough to ship whole and is filtered the same way a document
 * type list is: `status` narrows it, `search` narrows it, and an unknown
 * sort falls back rather than raising.
 *
 * **No row scope, and no policy beyond the route's permission.** A training
 * type describes no person: an Employee and an HR Admin read the same eight
 * words, because both need them to make sense of a course history. The
 * narrow rules this module is known for live on enrolments.
 *
 * A retired type is still listed unless `status=active` is asked for — a
 * cohort filed under a withdrawn kind has to remain readable, and a picker
 * that hid it would leave the courses underneath it unexplained.
 */
class TrainingTypeController extends Controller
{
    use BuildsResourceLists;

    public function index(Request $request): JsonResponse
    {
        // No policy, and deliberately so: a training type describes no
        // person, so there is no row for one to guard. The coarse
        // permission the route already requires is the whole answer, and
        // asking `authorize()` here would be inventing an ability that
        // maps to no policy class.
        abort_unless($request->user()?->can('training.view') === true, 403);

        $query = TrainingType::query();

        if ($status = $this->param($request, 'status')) {
            $query->where('status', $status);
        }

        if ($term = $this->searchTerm($request)) {
            $like = '%'.$this->escapeLike($term).'%';

            $query->where(function ($inner) use ($like) {
                $inner->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        $query->orderBy(
            $this->sortColumn($request, ['name', 'code', 'status', 'created_at'], 'name'),
            $this->sortDirection($request, 'asc'),
        );

        return ApiResponse::success(
            'Training types.',
            TrainingTypeResource::collection($query->get()),
        );
    }
}
