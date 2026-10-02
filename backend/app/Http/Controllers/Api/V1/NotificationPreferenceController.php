<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Notifications\NotificationCategory;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/notification-preferences, PUT /api/v1/notification-preferences
 *
 * One switch per *category*, not per type — twelve unrelated messages
 * behind twelve switches is a settings screen nobody reads. The full
 * catalogue comes back every time (`NotificationCategory::ALL`) rather
 * than only the rows somebody has written, because "absent means enabled"
 * has to be visible as an enabled switch, not as a missing line.
 *
 * A mandatory category (`security`, `system`) is reported `mandatory: true`
 * and `enabled: true`, and the write path refuses it with a 409 that names
 * the category. A 422 would be wrong — nothing about the *request* is
 * malformed; the request is asking for something the system will not do.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * GET /api/v1/notification-preferences
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            'Notification preferences.',
            ['items' => $this->notifications->preferences((int) $request->user()->id)],
        );
    }

    /**
     * PUT /api/v1/notification-preferences
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'enabled' => ['required', 'boolean'],
        ]);

        $category = (string) $data['category'];
        $userId = (int) $request->user()->id;

        if (! NotificationCategory::exists($category)) {
            return ApiResponse::error('Unknown notification category.', null, 422);
        }

        if (NotificationCategory::isMandatory($category)) {
            return ApiResponse::error(
                "The {$category} notifications are mandatory and cannot be switched off.",
                null,
                409,
            );
        }

        $row = $this->notifications->setPreference($userId, $category, (bool) $data['enabled']);

        // Return the whole catalogue, not just the row that changed: the
        // preferences screen is a single list, and answering with a
        // different shape from GET would mean two parsers for one screen.
        return ApiResponse::success(
            'Notification preference updated.',
            ['items' => $this->notifications->preferences($userId), 'changed' => $row],
        );
    }
}
