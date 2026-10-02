<?php

namespace App\Http\Resources;

use App\Models\DeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One registered install — and never its token.
 *
 * The `fcm_token` column is deliberately absent rather than nulled out
 * after the fact: `DeviceToken` already hides it, so the value never
 * arrives here to be forgotten again. A resource whose job is to prove
 * "these are the handsets that will be pinged" needs an id, a platform and
 * a last-seen; the credential that makes the ping work belongs to the
 * handset that holds it, and handing it back to any client — including its
 * owner — would give an attacker who read one list response the ability to
 * impersonate pushes to a device they do not have.
 */
class DeviceTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DeviceToken $device */
        $device = $this->resource;

        return [
            'id' => $device->id,
            'device_identifier' => $device->device_identifier,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'active' => $device->active,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }
}
