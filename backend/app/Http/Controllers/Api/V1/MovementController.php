<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attendance;
use App\Services\Attendance\MovementTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/movement/today
 *
 * The day as a single chronological list: check-in, visit start, visit end,
 * check-out — sorted by the moment each one happened.
 *
 * Deliberately self-only and deliberately narrow. It answers "what did I do
 * today?", which is the question the person holding the phone has. Turning
 * it into a manager's view is a scoping decision (the same one
 * Visibility::attendanceFor() already makes) and belongs with a list screen
 * that does not exist yet, not in an endpoint that would then be reachable
 * by anyone who guessed its name.
 *
 * There is no `permission:` middleware because every event returned is the
 * caller's own.
 */
class MovementController extends Controller
{
    public function __construct(private readonly MovementTimelineService $timeline) {}

    public function today(Request $request): JsonResponse
    {
        // Reuses the attendance ability: a person who may not open their own
        // attendance has no day to summarise, and there is no separate
        // "read my own timeline" grant to invent.
        $this->authorize('today', Attendance::class);

        return ApiResponse::success('Movement timeline retrieved.', $this->timeline->today($request->user()));
    }
}
