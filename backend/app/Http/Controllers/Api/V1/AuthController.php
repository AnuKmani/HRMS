<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POST /auth/login, /auth/logout, /auth/change-password, GET /auth/me plus
 * the session endpoints that make device management possible later.
 *
 * Stateless by design: nothing here writes to the `sessions` table or touches
 * the web guard, because the Flutter app authenticates with a bearer token.
 * See docs/SECURITY.md §"Sanctum token flow".
 */
class AuthController extends Controller
{
    /**
     * Exchange credentials for a bearer token.
     *
     * Deliberately does NOT use Auth::attempt(): that logs the user into the
     * session guard and writes a row to `sessions`, which is meaningless for a
     * token API. Hash::check performs the same bcrypt comparison with no side
     * effects.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        $user = User::query()->where('email', $credentials['email'])->first();

        // One identical answer for "no such user" and "wrong password" so the
        // endpoint cannot be used to enumerate registered addresses.
        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('The email or password you entered is incorrect.');
        }

        // Reached only after the password verified, so telling this account
        // apart costs nothing to an attacker who does not already know it.
        if (! $user->canLogIn()) {
            throw new AuthenticationException('This account has been deactivated. Contact your administrator.');
        }

        $device = $this->deviceName($request);

        // One live token per device. Signing in again on the same handset
        // replaces its previous session rather than orphaning a token nobody
        // can see or revoke any more.
        $user->tokens()->where('name', $device)->delete();

        /** @var NewAccessToken $newToken */
        $newToken = $user->createToken($device);

        $user->load(['employee.department', 'employee.designation']);

        return ApiResponse::success('Signed in successfully.', [
            'token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Who am I, and what am I allowed to do.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load(['employee.department', 'employee.designation']);

        return ApiResponse::success('Authenticated user.', new UserResource($user));
    }

    /**
     * Revoke the token this request presented — the current session only.
     * Other devices stay signed in; use sessions() to end those individually.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success('Signed out successfully.');
    }

    /**
     * Change the password and force every *other* device to sign in again.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        // Only possible when the caller presented a bearer token. If the
        // current token cannot be identified we revoke nothing rather than
        // accidentally ending the session we are talking on.
        $current = $user->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $user->tokens()->where('id', '!=', $current->id)->delete();
        }

        return ApiResponse::success('Password updated. Other devices have been signed out.');
    }

    /**
     * List this account's live sessions so a user can see where they are
     * signed in. Exposes identifiers and timestamps only — never the stored
     * hash, never abilities beyond "this token exists".
     */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;

        $sessions = $user->tokens()
            ->select(['id', 'name', 'last_used_at', 'created_at', 'expires_at'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'device' => $token->name,
                'is_current' => $token->id === $currentId,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])
            ->values();

        return ApiResponse::success('Active sessions.', $sessions);
    }

    /**
     * Revoke one of this account's sessions.
     *
     * 404 rather than 403 when the id belongs to someone else: a 403 would
     * confirm the token exists on another account.
     */
    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->first();

        if ($token === null) {
            abort(404, 'Session not found.');
        }

        $token->delete();

        return ApiResponse::success('Session revoked.');
    }

    /**
     * Device label stored as the token name. Cosmetic but load-bearing: it is
     * what the sessions list shows and what "replace this device's token"
     * matches on.
     */
    private function deviceName(Request $request): string
    {
        $device = Str::squish((string) $request->input('device_name', ''));

        return $device === '' ? 'API client' : Str::limit($device, 190, '');
    }
}
