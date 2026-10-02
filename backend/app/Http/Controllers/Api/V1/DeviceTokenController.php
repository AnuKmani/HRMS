<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceTokenResource;
use App\Http\Responses\ApiResponse;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/device-tokens, DELETE /api/v1/device-tokens/{deviceToken}
 *
 * Registration is an **upsert on (user, device)**, and the reason it has to
 * be is that a handset re-registers constantly: app start, token rotation,
 * sign-in on a new device, a reinstall that keeps its identifier. Treating
 * each call as an insert would leave one handset holding N live tokens and
 * every notification in the phase arriving N times.
 *
 * The `fcm_token` column is unique across the whole table, so one token
 * identifies one install. That means a token can *move* — Firebase
 * reassigns on reinstall, and a person can sign into a different account on
 * the same handset — and each of those moves would otherwise be a
 * constraint violation rather than a success. The transaction below hands
 * the token over: any other row claiming it is dropped first, then the
 * caller's row is written. Dropping, rather than deactivating, is correct
 * here because the row that lost the token is now *unreachable* — it is a
 * credential for a device that will never present it again.
 *
 * Deletion is scoped to the caller and answers 404 for anyone else's row,
 * same as the inbox: "not yours" and "does not exist" must look identical
 * from outside.
 *
 * `fcm_token` never appears in a response. It is read back only by the
 * queued push job, server-side.
 */
class DeviceTokenController extends Controller
{
    /**
     * POST /api/v1/device-tokens
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_identifier' => ['required', 'string', 'min:8', 'max:120'],
            'platform' => ['required', 'string', Rule::in(['android', 'ios', 'web'])],
            'fcm_token' => ['required', 'string', 'min:8', 'max:512'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();

        $device = DB::transaction(function () use ($user, $data) {
            // Hand the token over before claiming it — see the class
            // docblock. Scoped to *other* rows so the normal re-registration
            // case (same user, same device, rotated token) does not delete
            // the row it is about to update.
            DeviceToken::query()
                ->where('fcm_token', $data['fcm_token'])
                ->where(fn ($query) => $query
                    ->where('user_id', '!=', $user->id)
                    ->orWhere('device_identifier', '!=', $data['device_identifier']))
                ->delete();

            return DeviceToken::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'device_identifier' => $data['device_identifier'],
                ],
                [
                    'platform' => $data['platform'],
                    'fcm_token' => $data['fcm_token'],
                    'app_version' => $data['app_version'] ?? null,
                    'last_seen_at' => now(),
                    // Registering is an assertion that this handset is
                    // alive. A row Firebase reported dead a week ago comes
                    // back to life only when something actually presents a
                    // token for it — which is exactly this call.
                    'active' => true,
                ],
            );
        });

        return ApiResponse::created('Device registered for notifications.', new DeviceTokenResource($device));
    }

    /**
     * DELETE /api/v1/device-tokens/{deviceToken}
     *
     * Used at logout. The row goes rather than being deactivated: the
     * caller is saying "this handset no longer belongs to this session",
     * and `active = false` would still leave it receiving pushes addressed
     * to a person who has signed out. Deactivation is reserved for the
     * case where the row must survive as evidence — a token Firebase
     * rejected — and that is not this case.
     */
    public function destroy(Request $request, DeviceToken $deviceToken): JsonResponse
    {
        if ((int) $deviceToken->user_id !== (int) $request->user()->id) {
            abort(404, 'Device token not found.');
        }

        $deviceToken->delete();

        return ApiResponse::success('Device token removed.');
    }
}
