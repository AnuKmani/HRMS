<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InAppNotificationResource;
use App\Http\Responses\ApiResponse;
use App\Http\Responses\PaginatedResponse;
use App\Models\InAppNotification;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The in-app inbox: list, count, mark one read, mark everything read.
 *
 * **No permission gate on any of it.** Every row here was written *for* the
 * caller by `NotificationService`, so `auth:sanctum` is the whole
 * authorisation — a `notifications.view` permission would be a lie, since
 * there is no body of notifications a person is not allowed to see, only
 * rows addressed to them. Filtering is by `user_id` in the service, which
 * is why another account's id in the path returns 404 rather than 403: from
 * outside, "does not exist" and "is not yours" must look the same.
 *
 * The list is deliberately *not* filterable by arbitrary columns. `unread`
 * and `type` are the two questions a badge and a settings screen actually
 * ask; a `?search=` over inbox bodies would be a search endpoint over text
 * the app never shows in full anyway.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $query = InAppNotification::query()->forUser($userId);

        if ($this->truthy($request, 'unread')) {
            $query->unread();
        }

        if ($type = $request->query('type')) {
            if (is_string($type) && $type !== '') {
                $query->where('type', $type);
            }
        }

        // `latest('id')` rather than `latest('created_at')`: two rows can
        // share a second, and a pager that re-orders between pages shows
        // the same notification twice and drops another.
        $page = $query->latest('id')->paginate($this->perPage($request));

        return PaginatedResponse::make(
            'Notifications.',
            InAppNotificationResource::collection($page),
            $page,
        );
    }

    /**
     * GET /api/v1/notifications/unread-count
     *
     * Its own endpoint rather than a field on the list's `meta`: the badge
     * must be answerable without pulling page 1 of the inbox, and
     * `PaginatedResponse`'s meta shape is a contract every other list
     * endpoint shares.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success('Unread count.', [
            'unread' => $this->notifications->unreadCount((int) $request->user()->id),
        ]);
    }

    /**
     * POST /api/v1/notifications/{notification}/read
     */
    public function read(Request $request, int $notification): JsonResponse
    {
        if (! $this->notifications->markRead((int) $request->user()->id, $notification)) {
            return ApiResponse::error('Notification not found.', null, 404);
        }

        return ApiResponse::success('Notification marked as read.', [
            'id' => $notification,
            'read' => true,
            'unread' => $this->notifications->unreadCount((int) $request->user()->id),
        ]);
    }

    /**
     * POST /api/v1/notifications/read-all
     */
    public function readAll(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        return ApiResponse::success('All notifications marked as read.', [
            'updated' => $this->notifications->markAllRead($userId),
            'unread' => $this->notifications->unreadCount($userId),
        ]);
    }

    private function truthy(Request $request, string $key): bool
    {
        $value = $request->query($key);

        if (! is_scalar($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
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
