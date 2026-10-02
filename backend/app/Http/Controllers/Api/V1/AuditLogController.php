<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/audit-logs, GET /api/v1/audit-logs/options
 *
 * Gated by `audit.view`, enforced twice: `AuditLogPolicy` answers the
 * controller's `authorize('viewAny', ...)` and the route's `permission:`
 * middleware answers before it gets there. There is no *row*-level
 * question — an audit trail that only showed you the entries about people
 * you may already see would not be an audit trail, it would be the
 * existing screens with a different font. The permission is the whole
 * answer, and it is granted to the five roles an operator expects (Super
 * Admin, HR Admin, Payroll Admin, Finance, Management) rather than to the
 * people it records.
 *
 * Five filters, all optional and all AND-ed:
 *
 *   user_id        who acted — the session, so a scheduler's rows are only
 *                  reachable by leaving the filter empty.
 *   module         `leave`, `payroll`, `employee`, `credential`, ...
 *   action         `approval`, `create`, `lock`, `salary_change`, ...
 *   auditable_type full class name. The client sends `LeaveRequest` as
 *                  readily as `App\Models\LeaveRequest`; a bare class name
 *                  is expanded here rather than making the UI care which
 *                  one the server stored.
 *   from / to      date range, both ends inclusive — `AuditLog::scopeBetween`
 *                  owns the half-open conversion, not this controller.
 *
 * An unknown filter value returns an empty page rather than a 422. Filters
 * over a *catalogue* of short strings go stale the moment a new module is
 * added, and refusing a bookmarked URL for a value that did not exist last
 * Tuesday is a worse answer than showing that there is nothing under it.
 *
 * `module` and `action` are the two halves of one name — see
 * `AuditLogger` — so `?module=leave&action=approval` is "leave.approval"
 * and `?action=approval` alone is every approval in the system whatever it
 * was of. Both are worth a filter, which is why they are two columns.
 */
class AuditLogController extends Controller
{
    /**
     * GET /api/v1/audit-logs
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()->with('user:id,name');

        if ($userId = $request->query('user_id')) {
            if (is_scalar($userId) && (int) $userId > 0) {
                $query->forUser((int) $userId);
            }
        }

        if ($module = $request->query('module')) {
            if (is_string($module) && $module !== '') {
                $query->module($module);
            }
        }

        if ($action = $request->query('action')) {
            if (is_string($action) && $action !== '') {
                $query->action($action);
            }
        }

        if ($type = $this->recordType($request)) {
            $query->auditable($type);
        }

        $query->between(
            $this->date($request, 'from'),
            $this->date($request, 'to'),
        );

        // Newest first, and by id within a second: two rows written by one
        // bulk operation share a timestamp, and a pager that re-orders
        // between pages shows one twice and drops another.
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Audit log.',
            AuditLogResource::collection($page),
            $page,
        );
    }

    /**
     * GET /api/v1/audit-logs/options
     *
     * The distinct values the filter controls should offer, read from the
     * data rather than from a constant — so a module nobody has audited
     * yet does not appear as a filter that always yields nothing, and a
     * new action appears the first time it happens instead of the next
     * time somebody remembers to update a list in Flutter.
     */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        return ApiResponse::success('Audit filter options.', [
            'modules' => AuditLog::query()
                ->select('module')
                ->distinct()
                ->orderBy('module')
                ->pluck('module'),
            'actions' => AuditLog::query()
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
            'records' => AuditLog::query()
                ->select('auditable_type')
                ->distinct()
                ->orderBy('auditable_type')
                ->pluck('auditable_type')
                ->map(fn ($type) => [
                    'type' => $type,
                    'label' => class_basename($type),
                ])
                ->values(),
        ]);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * `LeaveRequest` and `App\Models\LeaveRequest` both accepted, because
     * the caller knows the class name and not necessarily the namespace it
     * was stored under.
     */
    private function recordType(Request $request): ?string
    {
        $type = $request->query('auditable_type', $request->query('record_type'));

        if (! is_string($type) || $type === '') {
            return null;
        }

        return str_contains($type, '\\') ? $type : 'App\\Models\\'.$type;
    }

    /**
     * A date that parses, or nothing — a garbage `from` is a filter the
     * caller did not mean to apply, and dropping it is friendlier than a
     * 422 on a query string.
     */
    private function date(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Respects the application-wide per-page config with a sane default.
     */
    private function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', config('hrms.pagination.per_page', 15));

        return max(1, min($requested, 100));
    }
}
