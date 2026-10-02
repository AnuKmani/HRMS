<?php

namespace App\Http\Resources;

use App\Models\InAppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of somebody's inbox.
 *
 * `data` is exposed deliberately — it is the *only* thing on a row that
 * makes tapping it useful (a route and the ids the screen needs), and it
 * holds no figure, no filename and no free text beyond a status word. See
 * the `notifications` migration for the rule this is an instance of.
 *
 * `type` ships whole rather than split into a `category` field, because the
 * client filters on prefixes (`leave.`, `payroll.`) and splitting it would
 * mean two ways to say the same thing.
 */
class InAppNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InAppNotification $notification */
        $notification = $this->resource;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'data' => $notification->data ?? [],
            'read' => ! $notification->isUnread(),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
